# Group bookings

## Purpose

Book many rooms (or beds) under one reservation.

## Shape

A group is a reservation with 2 or more units. This is worked out from the units, not stored. Units can have different dates, room types, rate plans and occupants.

`group_name` is an optional label. When it is empty, the UI shows the booker's name. `billing_mode` is stored on every reservation but only matters with 2 or more units: [Routing](../13-folio/03-routing.md) creates rules only then.

## Billing

| `billing_mode` | Room charges      | Extras            |
| -------------- | ----------------- | ----------------- |
| MasterPays     | Master folio      | Each unit's folio |
| EachPays       | Each unit's folio | Each unit's folio |

MasterPays creates routing rules automatically (see [Routing](../13-folio/03-routing.md)).

## Creating reservations and units

This item also builds the backend that creates every reservation: single rooms, groups and dorm beds. The [booking form](05-booking-form.md) and [channel ingestion](../21-channels/05-booking-ingestion.md) call it. It has no UI.

### Input

`app/Data/Reservations/` (spatie/laravel-data):

| Class                | Fields                                                                                                                                                                                                                                 |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `NewReservationData` | `booker_guest_id`, `source`, `channel_name`, `external_reference`, `company_id`, `group_name`, `billing_mode`, `units` (list of `NewUnitData`)                                                                                         |
| `NewUnitData`        | `room_type_id`, `rate_plan_id`, `arrival_date`, `departure_date`, `adults`, `child_ages` (list of int), `beds` (default 1), `discount_id`, `late_arrival_expected`, `override_total`, `override_reason`, `restriction_override_reason` |

The booker is an existing guest. Creating guests from the form tabs is in the booking form item. Both Actions take the acting `User $actor`, which becomes `created_by` and the activity log causer. How a channel booking, which has no staff user, fills `created_by` is decided in [booking ingestion](../21-channels/05-booking-ingestion.md).

### Result

Both Actions return a `ReservationResult` and never throw for an expected refusal (see `.ai/rules/actions.md`):

| Field         | Meaning                                                    |
| ------------- | ---------------------------------------------------------- |
| `reservation` | The saved reservation, or null when refused                |
| `refusal`     | A refusal code (constants on `ReservationResult`), or null |
| `lineIndex`   | The index in `units` of the refused line                   |
| `date`        | The night that caused the refusal, when there is one       |

Refusal codes: `room_type_not_found`, `rate_plan_not_linked`, `invalid_stay`, `arrival_in_past`, `adults_required`, `child_ages_mismatch`, `child_age_invalid`, `discount_not_allowed`, `restricted`, `restriction_override_not_allowed`, `not_available`, `override_reason_required`, plus the `RateQuote` refusal codes passed through as they are.

### `CreateReservation`

Creates the reservation and its units in one transaction.

- `reference` from `NextDocumentNumber` (`DocumentType::Reservation`).
- Sets the booker, `source`, `channel_name`, `external_reference`, `company_id`, `group_name`, `billing_mode` and `created_by`.
- For each line, calls `AddUnit` once per bed (`beds`, Bed room types) or once (Room room types). A Room room type with `beds` above 1 is refused as `invalid_stay`.
- When one line is refused, nothing is saved and the result names the line.

### `AddUnit`

Adds one unit to a reservation. It locks the room type row (`lockForUpdate`) before the availability check, so two staff cannot sell the last room or bed at the same time.

| Check, in order                                                                                                                                             | Refusal                                                                                                                                                              |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Room type and rate plan belong to the reservation's property                                                                                                | `room_type_not_found`                                                                                                                                                |
| The rate plan is linked to the room type (`rate_plan_room_types`)                                                                                           | `rate_plan_not_linked`                                                                                                                                               |
| Departure after arrival                                                                                                                                     | `invalid_stay`                                                                                                                                                       |
| Arrival on or after the current business date (`CurrentBusinessDate`, or `PropertyToday` before the first business date). Channel bookings skip this check. | `arrival_in_past`                                                                                                                                                    |
| Adults at least 1                                                                                                                                           | `adults_required`                                                                                                                                                    |
| One age per child. Ages are always required when there are children.                                                                                        | `child_ages_mismatch`                                                                                                                                                |
| Each age from 0 to 17. The quote prices an age past the property's last age group as an adult; `AddUnit` does no group matching itself.                     | `child_age_invalid`                                                                                                                                                  |
| `discount_id`, when given, is an active RoomOnly discount of the property                                                                                   | `discount_not_allowed`                                                                                                                                               |
| `RestrictionCheck::violations()` is empty                                                                                                                   | `restricted`. With a `restriction_override_reason`: allowed when the actor has `rates.override-restrictions` (logged), otherwise `restriction_override_not_allowed`. |
| `AvailabilityService::availableForStay()` is at least 1                                                                                                     | `not_available`. `source = Channel` may overbook (see [availability](../10-availability/01-availability-service.md)).                                                |
| An `override_total` comes with an `override_reason`                                                                                                         | `override_reason_required`                                                                                                                                           |
| `RateQuoteService::quote()` (with `beds` = 1) is not refused                                                                                                | The quote's refusal code                                                                                                                                             |

It then writes:

- The unit, with `property_id` from the reservation, `children` = number of ages, and `discount_id`.
- One `unit_nights` row per night: `rate_plan_id` and the quoted `base_amount`, `extra_amount`, `supplement_amount` and `discount_amount` (the rate plan's stay discount from the quote).
- One `reservation_unit_children` row per age.
- An activity log entry. It holds the restriction override reason and the price override reason when they are used.

Then it calls `SyncReservationStatus`.

**Price override**: `override_total` is the stay total for this one unit. It is spread evenly over the nights in `override_amount`, and the last night takes the rounding remainder. A bed line with an override gives every bed the same total.

**Catalogue discount**: `AddUnit` only stores `discount_id`. Its amount is not on `unit_nights`. The [folio](../13-folio/04-folio-discounts.md) calculates and posts it.

### `UpdateReservationGroup`

Changes `group_name` and `billing_mode`. [Routing](../13-folio/03-routing.md) adds its rules for a billing mode change in module 13.

## Supported cases

- Staggered arrivals and departures.
- Cancel one room without touching others.
- Check in room by room.
- Mixed private rooms and dorm beds.

## Lessons from wastays

`group_bookings` wrapped N `bookings` rows and duplicated reservation number, receipt number, revision and status fields. `IngestGroupBookingService` (437 lines) existed only for multi-room OTA bookings.
