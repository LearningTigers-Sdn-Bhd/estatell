# Change guests

## Purpose

Change the adults and child ages of one room after booking, and price the stay again for them. A per-guest resort (Sand Bay) prices every night by the number of guests, and a group often adds or drops a guest after booking. Before this, staff had to cancel the room and book it again.

## Where

The unit panel on the Units tab: the **Guests** row shows **Change** (`ReservationUnitData::canChangeGuests`) to users with `reservations.manage`, on a Confirmed or Checked-in overnight room. The Dialog (`ChangeGuestsDialog`) has **Adults**, one **Age** per child with **Add child** and ×, and a preview of each night before and after, read from the server as the form changes.

## Rules

| Part                                     | What happens                                                                                                                                                                                                                                   |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Nights not charged yet                   | The whole stay is quoted again with `RateQuoteService` for the new party, so the long-stay discount still counts every night. Each night takes its new base, extra, supplement, discount and amount                                            |
| A night with a price set by hand         | Keeps it (`override_amount`). The preview says "price set by hand"                                                                                                                                                                             |
| Charged nights                           | Keep their price. With **Also re-price nights already charged** (shown only with `reservations.change-price` and a charged night), they go through `ChangeNightPrice` at the new price, with the reason "Guests changed: 2 adults to 3 adults" |
| Per-guest add-ons not posted (jetty fee) | Removed and added again with `AddUnitAddOn`, so the quantity and the child rows follow the new party. The adult amount is kept when the item allows another amount                                                                             |
| Per-guest add-ons already posted         | Corrected on the folio, each with a tick box (on by default). See [Charged add-ons](#charged-add-ons)                                                                                                                                          |
| Boat fares                               | `ResyncBoatFares` writes the fare of each scheduled trip again. A trip whose fare is already charged is left alone, so a fare is never charged twice                                                                                           |
| Channel booking (source type OTA)        | The nights keep the OTA price. The party, add-ons and fares still change                                                                                                                                                                       |
| Meals, manifest, house status            | Read the unit's party, so they follow at once                                                                                                                                                                                                  |

A child age with no age group pays the adult price, as at booking.

## Charged add-ons

A per-guest item that is already charged (the jetty fee, charged at check-in) is corrected for the new party. `PostedAddOnAdjustments` (Service) compares, for each item and service date, the charged quantity of each age group with the quantity for the new party:

| Change                    | What is posted                                                                                               | Dialog line                                                          |
| ------------------------- | ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------- |
| More guests only          | New add-on rows for the difference, charged at once on the business date                                     | "Jetty Fee: Charge 1 more guest +RM 10.00"                           |
| Fewer guests in any group | The charged lines are reversed (`ReverseLine`), then the new party is added and charged on the business date | "Jetty Fee: Reverse 2 guests and charge 1 guest −RM 20.00 +RM 10.00" |

- **Prices:** each row keeps the price of the charged row of its age group, so a price the hotel typed stays. A new age group takes its child price of the charged adult amount. Free groups (infants) are never charged.
- **Manual instead:** staff untick a line and the folio stays as it is; they correct it by hand. The form sends the unticked keys in `skip_add_ons` ("{charge item id}|{service date}").
- **Permission:** a line that reverses needs `folio.reverse`. Without it the box is off and disabled ("Needs permission to reverse charges"), and the line is left for someone who can. Charging more needs no extra permission.
- **Left out:** boat fares (`ResyncBoatFares`) and per-guest-per-night items, whose charged nights are past.
- A refusal from `ReverseLine` or `PostCharge` rolls the whole change back. The toast adds "1 charged add-on corrected."

## Channel bookings

A Modified OTA revision whose room has other adults or children calls `ChangeUnitParty` with `channelRevision` (see [Booking ingestion](../21-channels/05-booking-ingestion.md)). The nights keep the OTA price, the per-guest add-ons and boat fares follow, charged add-ons are corrected, and `party_too_many_guests` never refuses it. Any other refusal leaves the guests as they were and the revision is still saved.

## Refusals

All checked before the first write. A refusal writes and logs nothing, and the Dialog shows it.

| Code                    | When                                                                        | Message                                                       |
| ----------------------- | --------------------------------------------------------------------------- | ------------------------------------------------------------- |
| `unit_not_open`         | The unit is not Confirmed or Checked in                                     | Only a confirmed or checked-in room can change its guests.    |
| `party_day_use`         | A day use unit                                                              | A day use booking keeps its guests. Cancel it and book again. |
| `party_bed_unit`        | A Bed room type                                                             | A bed takes one guest. Add or remove beds instead.            |
| `party_too_many_guests` | More adults or children than the room type's `max_adults` or `max_children` | This room type takes fewer guests.                            |
| `party_nothing_changed` | The same adults and ages                                                    | The guests are the same as now.                               |
| A quote refusal         | For example a missing rate or child price                                   | The shared quote message                                      |

A refusal from `ChangeNightPrice`, `AddUnitAddOn` or `ResyncBoatFares` rolls the whole change back.

## Build

- `UnitPartyChangePreview` (Service, `app/Services/Reservations/`) returns a `UnitPartyPlan`: every night with its price now and after, `charged`, `overridden` and `changes`, the totals before and after, and `fromChannel`. It writes nothing.
- `ChangeUnitParty` (Action, `app/Actions/Reservations/`) locks the unit, runs the plan, saves `adults`, `children` and `reservation_unit_children`, prices the nights, then rewrites the per-guest add-ons and the boat fares. It logs `reservation.party_changed` with the old and new party and totals. It returns `UnitPartyResult` (`repriced`, `corrected`, `adjustedAddOns`, or a refusal).
- Boats: `BoatFareAddOns` (Service) lists the fare rows of a unit, which `ChangeUnitParty` leaves to `ResyncBoatFares` (Action).
- Routes: `reservations.units.party.preview` (POST, JSON `UnitPartyPreviewData`) and `reservations.units.party` (PUT), both under `reservations.manage`. `ChangeUnitPartyRequest` takes `adults` (1–50), `child_ages` (0–17, at most 20) `reprice_charged`, which counts only with `reservations.change-price`, and `skip_add_ons`. The preview adds `addOns` (`UnitPartyAddOnData`: key, name, summary, reverse and charge amounts, `needsReversal`, `canApply`).
