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
| Per-guest add-ons already posted         | Do not change. Staff post or reverse the difference on the folio                                                                                                                                                                               |
| Boat fares                               | `ResyncBoatFares` writes the fare of each scheduled trip again. A trip whose fare is already charged is left alone, so a fare is never charged twice                                                                                           |
| Channel booking (source type OTA)        | The nights keep the OTA price. The party, add-ons and fares still change                                                                                                                                                                       |
| Meals, manifest, house status            | Read the unit's party, so they follow at once                                                                                                                                                                                                  |

A child age with no age group pays the adult price, as at booking.

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
- `ChangeUnitParty` (Action, `app/Actions/Reservations/`) locks the unit, runs the plan, saves `adults`, `children` and `reservation_unit_children`, prices the nights, then rewrites the per-guest add-ons and the boat fares. It logs `reservation.party_changed` with the old and new party and totals. It returns `UnitPartyResult` (`repriced`, `corrected`, or a refusal).
- Boats: `BoatFareAddOns` (Service) lists the fare rows of a unit, which `ChangeUnitParty` leaves to `ResyncBoatFares` (Action).
- Routes: `reservations.units.party.preview` (POST, JSON `UnitPartyPreviewData`) and `reservations.units.party` (PUT), both under `reservations.manage`. `ChangeUnitPartyRequest` takes `adults` (1–50), `child_ages` (0–17, at most 20) and `reprice_charged`, which counts only with `reservations.change-price`.
