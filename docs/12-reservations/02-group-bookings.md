# Group bookings

## Purpose

Book many rooms (or beds) under one reservation.

## Shape

A group is a reservation with 2 or more units. This is worked out from the units, not stored. Units can have different dates, room types, rate plans and occupants.

`group_name` is an optional label. When it is empty, the UI shows the booker's name. `billing_mode` is stored on every reservation but only matters with 2 or more units, or when the reservation has a `company_id`: [Routing](../13-folio/03-routing.md) creates rules only then.

## Billing

| `billing_mode` | Room charges      | Extras            |
| -------------- | ----------------- | ----------------- |
| MasterPays     | Master folio      | Each unit's folio |
| MasterPaysAll  | Master folio      | Master folio      |
| EachPays       | Each unit's folio | Each unit's folio |

MasterPays and MasterPaysAll create routing rules automatically (see [Routing](../13-folio/03-routing.md)).

## Creating reservations and units

This item also builds the backend that creates every reservation: single rooms, groups and dorm beds. The [booking form](05-booking-form.md) and [channel ingestion](../21-channels/05-booking-ingestion.md) call it. It has no UI.

### Input

`app/Data/Reservations/` (spatie/laravel-data):

| Class                | Fields                                                                                                                                                                                                                                                                                                                                      |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `NewReservationData` | `booker_guest_id`, `source`, `channel_name`, `external_reference`, `company_id`, `group_name`, `billing_mode`, `units` (list of `NewUnitData`)                                                                                                                                                                                              |
| `NewUnitData`        | `room_type_id`, `rate_plan_id`, `arrival_date`, `departure_date`, `adults`, `child_ages` (list of int), `beds` (default 1), `discount_id`, `late_arrival_expected`, `override_total`, `override_reason`, `restriction_override_reason`, `add_ons` (list of `NewAddOnData`, added by the [add-ons](04-add-ons.md#booking-with-add-ons) item) |

In code the properties are camelCase (`roomTypeId`, `arrivalDate`). Dates are `Y-m-d` strings, and `overrideTotal` is a decimal string. The booker is an existing guest. Creating guests from the form tabs is in the booking form item. Both Actions take the acting `User $actor`, which becomes `created_by` and the activity log causer. How a channel booking, which has no staff user, fills `created_by` is decided in [booking ingestion](../21-channels/05-booking-ingestion.md).

### Result

Both Actions return a `ReservationResult` and never throw for an expected refusal (see `.ai/rules/actions.md`):

| Field         | Meaning                                                    |
| ------------- | ---------------------------------------------------------- |
| `reservation` | The saved reservation, or null when refused                |
| `refusal`     | A refusal code (constants on `ReservationResult`), or null |
| `lineIndex`   | The index in `units` of the refused line                   |
| `date`        | The night that caused the refusal, when there is one       |

Refusal codes: `units_required`, `booker_not_found`, `room_type_not_found`, `rate_plan_not_linked`, `invalid_stay`, `arrival_in_past`, `adults_required`, `child_age_invalid`, `discount_not_allowed`, `restricted`, `restriction_override_not_allowed`, `not_available`, `override_reason_required`, `override_invalid`, `reservation_closed`, plus the `RateQuote` and [add-on](04-add-ons.md#addunitaddon) refusal codes passed through as they are.

### `CreateReservation`

Creates the reservation and its units in one transaction.

- At least one line. An empty `units` list is refused as `units_required`.
- The booker belongs to the property and is not merged or anonymized, otherwise `booker_not_found`.
- Locks every room type of the request in id order before the first line, so two group bookings cannot deadlock. `AddUnit` locks the same rows again at no cost.
- `reference` from `NextDocumentNumber` (`DocumentType::Reservation`).
- Sets the booker, `source`, `channel_name`, `external_reference`, `company_id`, `group_name`, `billing_mode` and `created_by`.
- For each line, calls `AddUnit` once per bed (`beds`, Bed room types) or once (Room room types). A Room room type with `beds` above 1 is refused as `invalid_stay`.
- When one line is refused, nothing is saved (the reference number is not used up) and the result names the line. Refusals write no activity.

### `AddUnit`

Adds exactly one unit to a reservation and ignores `beds`. It refuses a Cancelled or Completed reservation as `reservation_closed`, before any other check, so `SyncReservationStatus` never reopens it.

**Channel ingestion**: `CreateReservation` and `AddUnit` take `bool $fromChannel = false`. Only [booking ingestion](../21-channels/05-booking-ingestion.md) passes true, and only then are the past-arrival, restriction and availability checks skipped. A Channel-source reservation edited by staff (Add unit on the reservation page) gets every check.

Callers loop for bed lines: `CreateReservation`, and Add unit on the reservation page. It locks the room type row (`lockForUpdate`) before the availability check, so two staff cannot sell the last room or bed at the same time.

| Check, in order                                                                                                                                                                  | Refusal                                                                                                                                                                                                                  |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Room type and rate plan belong to the reservation's property. An archived room type counts as not found; an archived plan is refused by the quote (`plan_archived`).             | `room_type_not_found`                                                                                                                                                                                                    |
| The rate plan is linked to the room type (`rate_plan_room_types`)                                                                                                                | `rate_plan_not_linked`                                                                                                                                                                                                   |
| Departure after arrival                                                                                                                                                          | `invalid_stay`                                                                                                                                                                                                           |
| Arrival on or after the current business date (`CurrentBusinessDate`, or `PropertyToday` before the first business date). Ingestion skips this check.                            | `arrival_in_past`                                                                                                                                                                                                        |
| Adults at least 1                                                                                                                                                                | `adults_required`                                                                                                                                                                                                        |
| Each age from 0 to 17. The quote prices an age past the property's last age group as an adult; `AddUnit` does no group matching itself.                                          | `child_age_invalid`                                                                                                                                                                                                      |
| `discount_id`, when given, is an active RoomOnly discount of the property                                                                                                        | `discount_not_allowed`                                                                                                                                                                                                   |
| `RestrictionCheck::violations()` is empty. Ingestion skips this check: the OTA already checked its own copy (see [daily rates](../08-rates/02-daily-rates-and-restrictions.md)). | `restricted`. With a `restriction_override_reason`: allowed when the actor has `rates.override-restrictions` (logged), otherwise `restriction_override_not_allowed`. A reason given when nothing is violated is ignored. |
| `AvailabilityService::availableForStay()` is at least 1                                                                                                                          | `not_available`. Ingestion may overbook (see [availability](../10-availability/01-availability-service.md)).                                                                                                             |
| An `override_total` comes with an `override_reason`, and is 0 or more with at most two decimals (0 is a complimentary stay). No permission is needed.                            | `override_reason_required`, `override_invalid`                                                                                                                                                                           |
| `RateQuoteService::quote()` (with `beds` = 1) is not refused                                                                                                                     | The quote's refusal code                                                                                                                                                                                                 |

It then writes:

- The unit, with `property_id` from the reservation, `children` = number of ages (so ages are always given for every child), and `discount_id`.
- One `unit_nights` row per night: `rate_plan_id` and the quoted `base_amount`, `extra_amount`, `supplement_amount` and `discount_amount` (the rate plan's stay discount from the quote).
- One `reservation_unit_children` row per age.
- An activity log entry. It holds the restriction override reason and the price override reason when they are used.

Then it calls `SyncReservationStatus`.

The `rates.override-restrictions` check runs with the permission team set to the reservation's property, then restores the previous team (like `AssignStaffRole`).

**Price override**: `override_total` is the stay total for this one unit. It is spread evenly over the nights in `override_amount`, rounded down to the cent, and the last night takes the remainder (100.00 over 3 nights is 33.33, 33.33, 33.34). A bed line with an override gives every bed the same total.

**Catalogue discount**: `AddUnit` only stores `discount_id`. Its amount is not on `unit_nights`. The [folio](../13-folio/04-folio-discounts.md) calculates and posts it.

### `UpdateReservationGroup`

Changes `group_name` and `billing_mode`. [Routing](../13-folio/03-routing.md) adds its rules for a billing mode change in module 13.

## Build status

Built: `CreateReservation`, `AddUnit`, `UpdateReservationGroup`, `ReservationResult`, `NewReservationData`, `NewUnitData` and `unit_nights.override_amount` (with its CHECK), plus `reservation_closed` and the `fromChannel` argument. The Add unit button, the Edit group dialog and the booking form call them in later items.

## Supported cases

- Staggered arrivals and departures.
- Cancel one room without touching others.
- Check in room by room.
- Mixed private rooms and dorm beds.

## Lessons from wastays

`group_bookings` wrapped N `bookings` rows and duplicated reservation number, receipt number, revision and status fields. `IngestGroupBookingService` (437 lines) existed only for multi-room OTA bookings.
