# Bed bookings

## Purpose

Sell dorm beds one by one.

## Shape

N beds = N units in one reservation, one occupant per unit.

## Rules

- The booking form shows "Beds: N" for Bed room types. `NewUnitData.beds` carries N, and `CreateReservation` calls `AddUnit` once per bed (see [Group bookings](02-group-bookings.md#createreservation)). Availability counts each bed.
- One guest per bed unit is enforced by `AttachGuest` (`bed_unit_full`) in the [reservation page](06-reservation-page.md#guests-tab) item. A room unit holds its adults + children (`unit_full`).
- Built with the [Group bookings](02-group-bookings.md) item. There is no separate bed item.
- Each bed is stored on `unit_nights.bed_id`.
- Each backpacker can check in, move bed, check out or be marked no-show independently.
- With EachPays, each unit gets its own folio.

## Build status

Built with `CreateReservation` and `AddUnit` (see [Group bookings](02-group-bookings.md)). `AttachGuest` comes with the reservation page; assigning a bed (`bed_id`) comes with the booking form.

## Whole dorm (decided 2026-11-23)

A group can buy out a whole dorm. Every dorm can be bought out, but only when **every bed is free on every night** of the stay. It is booked from the staff booking form only, not from channels or the booking engine.

**Shape.** A whole-dorm booking is still one bed unit per bed, so availability, channels, housekeeping, check-in and check-out work as for beds. Each of those units has `reservation_units.whole_room_id` set to the dorm. The reservation page shows them as "8-Bed Mixed Dorm · Whole dorm D3".

**Booking form.** A dorm line has a **Whole dorm** select next to Beds: "Beds only" or each dorm of the room type whose every bed is free for the dates ("Whole dorm D3 · 8 beds"). Picking a dorm sets Beds to its bed count, locks it, and hides the bed pickers. The list comes from `FreeUnits::wholeRooms()` (the `wholeRooms` key of `reservations.free-units`). A picked dorm that is no longer free is cleared.

**Price.** The line uses any bed plan the dorm sells, so the hotel prices a buyout in one of two ways:

| Way                               | How                                                                                                               |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Bed price × beds, less a discount | A [derived](../08-rates/04-derived-rate-plans.md) Per bed plan, such as "Whole dorm −10%", following the bed plan |
| A fixed price for the dorm        | The line's price override, labelled **Stay total of the whole dorm**                                              |

The override is the dorm's stay total. `CreateReservation::addWholeRoom()` splits it over the beds, a cent more on the first beds until it adds up (RM 200 over 3 beds: 66.67, 66.67, 66.66). The quote (`BookingQuote::line()` with `whole_room`) shows the same split.

**Rules.** `CreateReservation::addWholeRoom()` refuses a room that is not an active dorm of the line's room type (`whole_room_not_found`) and a dorm with any bed booked or blocked on a night of the stay (`whole_room_not_free`). It adds the units through `AddUnit`, so the availability and restriction checks still apply, then puts each unit on its bed with `AssignBed`.

**Gender.** Dorms have no gender rule in estatell, so a mixed group can buy out any dorm. If a rule is added later, the hotel decides whether a buyout may ignore it.

**Not yet.** Selling a whole dorm on channels or the booking engine (it would need its own room type with availability worked out from the beds).
