# Change a night's price

## Purpose

Change the price of one or more nights of a unit after booking: a corporate rate agreed late, a wrong rate the night audit already charged, or a complimentary night. One action covers nights that are still Scheduled and nights that are already charged, so staff do not need to know which is which.

Before this, a price override was set only at booking (`override_total` in `CreateReservation`, `AddUnit` and `ReinstateUnit`). A charged night could be lowered only with a folio discount and could not be charged again: `PostRoomCharges` replays an existing `night:{id}` line, and Post charge offers charge items only, so a re-charge landed outside Room revenue.

## Entry

| Where                                                         | Opens                                   |
| ------------------------------------------------------------- | --------------------------------------- |
| Units tab night table: **Change price**                       | The dialog, with the ticked nights      |
| Folio tab, row menu of a posted room line: **Correct price…** | The same dialog, with that night ticked |

Both show to users with `reservations.change-price`, for a unit that is Confirmed, CheckedIn or CheckedOut.

## Dialog

```
┌ Change price · Room 101 · Tan Wei Ming ───────────────────┐
│ Nights   [ All nights (3)                           ▾ ]   │
│ New price per night   [ 220.00 ]   was 250.00             │
│ Reason                [ Corporate rate agreed          ]  │
│                                                           │
│ 13 Oct is already charged. It will be reversed and        │
│ charged again at 220.00 with its breakfast and taxes.     │
│ 14–15 Oct will be charged at 220.00 by the night audit.   │
│ Difference                              −MYR 97.20        │
│                                      [Cancel] [Change]    │
└───────────────────────────────────────────────────────────┘
```

- **Nights** is a multi-select (`MultiSelectWithAll`, shared with the group Dialogs) with **All nights (N)** first. A charged night reads "13 Oct 2026 (charged)". The nights ticked on the night table, or the one night of Correct price…, are picked when it opens. Price and Reason have examples as placeholders.
- **One price per night**, applied to every ticked night. "was" shows the current `amount` (the lowest and highest when the ticked nights differ). A total spread over nights is not offered: it makes odd cents. The booking form keeps its total override.
- **Reason** is required (up to 500 characters).
- **Difference**: the change in what the guest pays, with tax, from the read-only preview (`NightPriceChangePreview`, 300 ms after a change).
- A night that had a discount says: "Discounts do not apply to a changed price." This is the existing rule: an override replaces the whole sum, so it has no discount lines.
- Price 0 is a complimentary night.

## What each night gets

| Night                                    | What happens                                                                                                                                                                                                                                                                                                                                                          |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Scheduled** (no `night:{id}` line yet) | Sets `override_amount`. Nothing posts now. The folio tab shows the new Scheduled rows at once, and the night audit charges the new price.                                                                                                                                                                                                                             |
| **Charged**                              | Sets `override_amount`, then reverses every live line of the night (the room line, its discounts, its inclusion lines and an earlier correction, each with its tax children), re-quotes the night with `NightChargeQuote` and posts the new lines on the **business date** under `night:{id}:fix:{n}`. The room line description ends "· night of 13 Oct, corrected". |

- `n` counts the night's corrections (1, 2, …), so a night can be corrected again. Each line of a correction takes the night keys with that prefix: `night:{id}:fix:{n}`, `night:{id}:fix:{n}:incl:{inclusion_id}`, and the tax keys after them.
- The new lines are routed by `FolioRouter` with today's rules, like any posting. They can land on another folio than the old lines when the rules changed since.
- The old lines keep their posting date. **Closed dates stay closed**: today's revenue shows the reversal and the new charge, which net to the difference.
- `unit_nights.amount` follows the override (database CHECK), so the money strip, the stay total and the booking summary agree with the folio.
- The night audit stays quiet: `night:{id}` still exists, so `PostRoomCharges` replays it and never charges a corrected night twice. `FolioStatement` treats the latest `:fix:` lines as the night's posted lines, so no Scheduled row comes back.

## `ChangeNightPrice`

`(User $actor, ReservationUnit $unit, list<UnitNight> $nights, string $price, string $reason): NightPriceResult` in `app/Actions/Reservations/`.

| Check, in order                                       | Refusal                                                                 |
| ----------------------------------------------------- | ----------------------------------------------------------------------- |
| The unit is Confirmed, CheckedIn or CheckedOut        | `invalid_status`                                                        |
| At least one night, all of this unit                  | `nights_invalid`                                                        |
| The price is 0 or more with at most two decimals      | `price_invalid`                                                         |
| A reason is given                                     | `reason_required`                                                       |
| A business date exists (for a charged night)          | `no_business_date`                                                      |
| Every folio holding a line of a charged night is Open | `folio_not_open`, or `folio_settled_to_city_ledger`                     |
| The new quote is calculated (inclusions, tax)         | the quote's refusal (`exceeds_night_amount`, `nationality_required`, …) |
| The target folio of each new line is Open             | `folio_not_open`, or `folio_settled_to_city_ledger`                     |

- One transaction, with the unit row and every touched folio locked in id order. Every check runs before the first write, so a refusal writes nothing.
- A price equal to the current `amount` on a night with no discount is skipped, not refused.
- It posts through `PostNightLines` (`app/Actions/Folio/`), the posting step `PostRoomCharges` also uses: route each charge of the quote, create the unit folio when missing, `PostFolioCharge`. Here it gets the `:fix:` keys and the "corrected" description, so the pipeline is the same as the audit's. `PostNightLines` runs no checks and logs nothing; each caller checks first, inside its own transaction.
- The old lines are reversed with `ReverseLine::post()` on each top-level line of the night (the room line, each inclusion, an earlier correction). It reverses the children (discounts, taxes) with their parent. `ChangeNightPrice` has run the checks already, so it does not call `ReverseLine` itself, which would open its own transaction and log one entry per line. `TransferLines` reverses lines the same way.
- Last, `CloseSettledUnitFolio` on each touched folio of a CheckedOut unit.
- One activity entry on the reservation, `reservation.night_price_changed`: the unit, the nights with old and new price, which were charged, and the reason. The night table shows the reason as it shows a booking override.
- **A settled folio** cannot take the correction. Use a credit note ([AR invoices](../15-city-ledger/02-ar-invoices.md)) or a second invoice through a third-party folio.
- **Apply discount stays** for goodwill on a charged night. Change price corrects the rate; a discount reduces it for a reason.

## Routes and permission

| URL                                                            | Name                                     | Permission                  |
| -------------------------------------------------------------- | ---------------------------------------- | --------------------------- |
| `POST …/{reservation}/units/{unit}/night-price/preview` (JSON) | `reservations.units.night-price.preview` | `reservations.change-price` |
| `POST …/{reservation}/units/{unit}/night-price`                | `reservations.units.night-price`         | `reservations.change-price` |

`reservations.change-price` ("Change the price of booked nights"): Owner, Manager and Finance by default. Front desk does not get it by default: it has no `folio.reverse`, and changing a charged price reverses lines. A property can still give it to Front desk in the roles grid. A booking-time override still needs no permission; changing a price later touches revenue, so it has its own.

## Change the price of a group

**Status: planned** (Sand Bay feedback 2). Set one price per night on every night of several units at once, for a group rate agreed after booking.

### Entry

Units tab, All view: **Change price** in the toolbar, left of the hide summary button (see [All and Grouped views](06-reservation-page.md#all-and-grouped-views)). It shows to users with `reservations.change-price` when at least one unit is Confirmed, CheckedIn or CheckedOut and has nights. Changing the price of single nights stays in the unit panel (Grouped view).

### Dialog

```
┌ Change price ─────────────────────────────────────────────┐
│ Units                 [ All units (5)                ▾ ]  │
│ New price per night   [ 600.00 ]                          │
│ Reason                [ Agent group rate               ]  │
│                                                           │
│ Unit                      Nights   Was        Difference  │
│ Deluxe Twin · F1          1        684.00     −MYR 84.00  │
│ Deluxe Twin · F2          1        684.00     −MYR 84.00  │
│ …                                                         │
│ Total difference                              −MYR 420.00 │
│                                      [Cancel] [Change]    │
└───────────────────────────────────────────────────────────┘
```

- The layout is the two-column Dialog of the [group add-on](04-add-ons.md#dialog) (`size="lg"`): Price and Reason on the left, the shared Preview panel (`GroupPreviewPanel`) on the right, one column below `lg`.
- **Units** is the shared `UnitsMultiSelect` of the group add-on, with **All units (N)** first and every unit that can change picked when the Dialog opens. Only units that are Confirmed, CheckedIn or CheckedOut and have nights are offered.
- Every night of each picked unit gets the new price, so units with different dates each keep their own nights. The button reads "Change 5 units".
- **Was** is the unit's current price per night (lowest and highest when its nights differ). **Difference** is with tax, from `GroupNightPricePreview`, which runs `NightPriceChangePreview::preview()` for each unit with all its nights (300 ms after a change). The total shows once every unit has one.
- A refusal in the preview (a closed folio, a quote refusal) lists "Unit: message" under the fields and keeps **Change** off.
- The note under the table says the difference includes tax, and adds "Charged nights are reversed and charged again." when a unit has one.
- Units at different prices (two room types): run it once per room type.
- Price 0, the reason and the discount note follow the single-unit Dialog.

### `ChangeGroupNightPrice`

`(User $actor, Reservation $reservation, list<int> $unitIds, string $price, string $reason): GroupNightPriceResult` in `app/Actions/Reservations/`.

- One transaction. It locks the picked units in id order, then calls `ChangeNightPrice` once per unit with all of that unit's nights.
- All or nothing: the first refusal rolls every unit back. `GroupNightPriceResult` holds the refusal code with the unit id and label, so the Dialog says "F3: The folio is closed."
- A unit id that is not a unit of the reservation is ignored. No unit left is refused (`no_units_selected`).
- Each unit keeps its own `reservation.night_price_changed` entry. No summary entry is written.

### Routes

| URL                                               | Name                               | Permission                  |
| ------------------------------------------------- | ---------------------------------- | --------------------------- |
| `POST …/{reservation}/night-price/preview` (JSON) | `reservations.night-price.preview` | `reservations.change-price` |
| `POST …/{reservation}/night-price`                | `reservations.night-price`         | `reservations.change-price` |

### Tests

- Three units with different dates: each gets the price on its own nights.
- A unit with a charged night is reversed and charged again under `:fix:1`; the others get `override_amount` only.
- One refused unit (a closed folio) refuses the whole save, names the room and writes nothing.
- Permission 403 and other-property 404.

## Tests

- A Scheduled night gets the override and posts nothing; the audit later charges the new price.
- A charged night: every line of the night (room, discount, inclusions, taxes) is reversed, the new lines post on the business date under `:fix:1`, and a second change uses `:fix:2`.
- The audit never charges a corrected night again, and the folio tab shows no Scheduled row for it.
- A closed folio is refused; a settled folio is refused with `folio_settled_to_city_ledger`.
- Price 0 posts a complimentary night with no tax on 0.
- A refusal writes nothing. Permission 403 and other-property 404.
