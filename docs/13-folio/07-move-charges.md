# Move charges

## Purpose

Move many posted charges from several folios of a reservation into one folio in one step, and choose where each room's scheduled charges will be charged. For example, a company agrees to pay every room of a group, or room charges go back to a unit folio that holds an advance.

It replaces the single-row Transfer Dialog. The row menu item **Move…** opens this page with that line ticked.

## Shape

| Item       | Value                                                                                                                                                                                                                                                                                                                               |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Page       | A full page, two columns: **From** and **To**. A recorded exception to the one-column full page rule (see [Form containers](../design-system/05-form-containers.md#sizes)).                                                                                                                                                         |
| URL        | `GET /p/{code}/reservations/{reservation}/folio/move?to={folio}&line={line}` (`reservations.folio.move`). `to` is the starting target, `line` an optional line to tick at the start.                                                                                                                                                |
| Permission | `folio.transfer` (its label is already "Transfer folio lines and manage routing", which covers the rules this page creates). No new permission.                                                                                                                                                                                     |
| Entry      | **Move charges** in the folio header (an Open folio, with at least one other Open folio), and **Move…** in the row menu of a movable charge. **Move…** ticks that line and starts **To** on the Master folio, or on the first other Open folio when the line is on the Master. **Cancel** goes back to the folio tab on the target. |
| Writes     | `MoveCharges` (Action, `app/Actions/Folio/`): the posted move and the scheduled rules in one transaction.                                                                                                                                                                                                                           |
| Reads      | `MoveChargesPreview` (service, `app/Services/Folio/`): the movable charges, the scheduled blocks and the balances before and after.                                                                                                                                                                                                 |

## Layout

```
Move charges · WA1-0142 · Tan family trip                              [Cancel] [Move 30 charges]
Charges move now. Scheduled charges follow the rule you choose, until you change it in Routing rules.
┌ FROM ─────────────────────────────────────┐     ┌ TO  [Master · Acme Sdn Bhd ▾] ──────────────┐
│ Quick select (Room) (All charges) [Clear] │     │ Balance    0.00 → 810.00                     │
│                                           │     │ Projected  0.00 → 1,350.00                   │
│ Unit 101 · Tan Wei Ming                   │     │                                              │
│ Balance 810.00 → 0.00                     │  →  │ INCOMING                                     │
│  ☑ 12 Oct  Room         250.00 +tax 20.00 │     │ Unit 101 · Tan Wei Ming                      │
│  ☑ 13 Oct  Room         250.00 +tax 20.00 │     │   12 Oct  Room          270.00          [×]  │
│  ☑ 14 Oct  Room         250.00 +tax 20.00 │     │   13 Oct  Room          270.00          [×]  │
│  ☐ 13 Oct  Laundry       10.00            │     │   14 Oct  Room          270.00          [×]  │
│                                           │     │   Scheduled: room nights 15–16 Oct           │
│ SCHEDULED · not charged yet               │     │              2 nights   540.00               │
│  ● Charge room nights to Master · Acme…   │     │                                              │
│    15–16 Oct · 2 nights · MYR 540.00      │     │ ALREADY HERE                                 │
│  ○ Charge everything to Master · Acme…    │     │   No charges yet                             │
│    Room nights, Island trip 16 Oct and    │     │                                              │
│    any later charge · MYR 900.00          │     │                                              │
└───────────────────────────────────────────┘     └──────────────────────────────────────────────┘
```

On a narrow screen the columns stack: **From** first, a ↓ in place of the →, then **To**. The footer keeps the count and the Move button.

## From

- Every Open folio of the reservation except the target, one group each, named by `FolioStatement::optionLabel()`, with **Balance {before} → {after}**.
- **Movable charges**: posted charge lines that are not a child, not a reversal and not reversed, and not owned by a payment. The same rules as `TransferLines`. Tax and discount children show under their charge with the amount ("+tax 20.00") and always move with it.
- **Quick select**: **Room** ticks every movable Room charge, **All charges** every movable charge, **Clear** unticks all.
- **Not listed**: payment and refund lines (payments never move; move the charges to the money), Closed folios (a checked-out unit's folio must be reopened first; a folio settled to the city ledger never moves), and Voided folios.

## To

- A Select of the Open folios (`optionLabel()`). It starts on `to`. Changing it moves the old target back to the From side and drops the ticks of the new target's own lines.
- **Balance {before} → {after}** (posted only) and **Projected {before} → {after}** (with the Scheduled rows), the same two figures as the [folio tab](05-folio-page.md#figures).
- **Incoming**: the ticked charges grouped by room and guest, each with **×** to untick it, then the scheduled blocks that will be charged here.
- **Already here**: the target's own posted charges, muted and read only. "No charges yet" when it has none.

## Scheduled charges

A Scheduled row is not a ledger line. The night audit posts it later to the folio the [routing rules](03-routing.md) pick, so moving it means adding a Manual rule. Rules have no dates, so the choice is per room, for all its scheduled nights.

| Choice (one per room, default off) | Creates                                                                           | Charges to the target                                            |
| ---------------------------------- | --------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| **Charge room nights to {to}**     | A Manual rule: this unit + Room → target                                          | The room nights, with their inclusions and tax                   |
| **Charge everything to {to}**      | Two Manual rules: this unit + Room → target, and this unit + all charges → target | Room nights, scheduled add-ons and any later charge of that room |

- **Why two rules for "everything".** A category rule for all units outranks a rule for all categories ([Routing](03-routing.md#service)), so "this unit + all charges" alone would lose to a BillingMode "all units + Room" rule. The unit's own Room rule wins at the category level.
- **The preview routes the scheduled rows** with the proposed rules (`FolioRouter` takes the proposed rules as an optional, read-only list). A row that a more specific rule keeps elsewhere stays there, and the To column says so: "Island trip 16 Oct stays on {folio}: a rule sends diving charges there."
- **An existing Manual rule with the same selectors** and another target is replaced: deleted and created again inside `MoveCharges`. A BillingMode rule is never touched; the new Manual rule outranks it for that unit.
- **A rule pointing at the unit's own folio** sends the charges back to it (the router's "own folio" case).
- A room with no Scheduled rows shows no block.
- Later changes happen in the existing Routing rules Sheet, where the rules show as Manual.

## Wording

Every folio name in labels, notes, the toast and the log comes from `optionLabel()` ({to} for the target, {from} for a source). The page never writes a folio name itself.

| Element                                          | Text                                                                                                        |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| Page title                                       | "Move charges · {reference} · {group name or booker}"                                                       |
| Note under the title                             | "Charges move now. Scheduled charges follow the rule you choose, until you change it in Routing rules."     |
| Scheduled header                                 | "SCHEDULED · not charged yet" (the folio tab's word)                                                        |
| Choice 1                                         | "Charge room nights to {to}", detail "15–16 Oct · 2 nights · MYR 540.00"                                    |
| Choice 2                                         | "Charge everything to {to}", detail "Room nights, Island trip 16 Oct and any later charge · MYR 900.00"     |
| Posted Room charges ticked, schedule left behind | "2 scheduled nights of Room 101 will still be charged to {from}."                                           |
| A row kept by a more specific rule               | "{charge} stays on {folio}: a rule sends {category or code} charges there."                                 |
| Button                                           | "Move 30 charges", "Move 30 charges and 10 schedules", "Move 10 schedules", or "Move charges" (disabled)    |
| Toast                                            | "30 charges moved to {to}." and, with schedules, " Scheduled nights of 10 rooms will be charged there too." |
| Activity log                                     | "Moved 30 charges (MYR 8,100.00) to {to} from 10 folios. Scheduled room nights of 10 rooms now go to {to}." |

Words the page does not use: "Upcoming" (the folio tab says Scheduled), "post" (staff say charge), "routing rule" in a choice label, "here" (two columns make it unclear) and "Transfer" (the page and the row menu say Move).

## `MoveCharges`

`(User $actor, Folio $target, list<FolioLine> $lines, list<array{unit: ReservationUnit, scope: 'room'|'all'}> $schedules): MoveChargesResult`

| Check                                                                                 | Refusal                 |
| ------------------------------------------------------------------------------------- | ----------------------- |
| At least one line or one schedule                                                     | `nothing_to_move`       |
| At most 200 posted charges (parents; children do not count)                           | `too_many_charges`      |
| Every line and unit belongs to the target's reservation                               | `different_reservation` |
| The `TransferLines` checks: Open folios, movable lines, no payment line               | its refusal codes       |
| Each schedule unit is not Cancelled or NoShow and has a night after the business date | `nothing_scheduled`     |

- One transaction. It locks the target and every source folio in id order, runs every check before the first write, so one bad line refuses the whole move and writes nothing.
- The posted charges go through `TransferLines`, which accepts lines from **several source folios** of one reservation (it accepted one). All copies share one `transfer_group_id`.
- The rules go through `CreateRoutingRule::__invoke` (which writes no log), after deleting a same-selector Manual rule.
- **One activity entry** on the reservation, `folio.charges_moved`, with the count, the amount, the target, the source folios and the rules created or replaced. The inner calls write no log of their own (how `TransferLines` skips its `folio.lines_transferred` entry here is decided at build time).
- Last, `CloseSettledUnitFolio` on each source, so a CheckedOut unit's folio left at 0 closes itself.
- **Replays.** A second submit finds the lines already reversed and is refused (`line_not_transferable`), so a double click moves once. A rule that already exists with the same target is not an error.

## Routes

| URL                                              | Name                              | Does                                                        |
| ------------------------------------------------ | --------------------------------- | ----------------------------------------------------------- |
| `GET …/{reservation}/folio/move`                 | `reservations.folio.move`         | The page                                                    |
| `POST …/{reservation}/folio/move/preview` (JSON) | `reservations.folio.move.preview` | `MoveChargesPreview`, writes nothing, 300 ms after a change |
| `POST …/{reservation}/folio/move`                | `reservations.folio.move.store`   | `MoveCharges`                                               |

All need `folio.transfer`. A folio, line or unit of another reservation is a 404. The old `reservations.folios.transfers.store` route and its Dialog are removed.

## Edge cases

| Case                                            | Behaviour                                                                                                              |
| ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Two staff move the same line                    | The second move is refused (`line_not_transferable`) and writes nothing                                                |
| A discount on a source charge                   | Moves with its charge, as a child                                                                                      |
| Revenue reports                                 | The reversal and the copy share a posting date and net to 0 that day; the original revenue keeps its date              |
| Reports by unit and the booking summary         | Each copy keeps its line source, so it still groups under its room                                                     |
| More than 200 charges                           | Refused; staff move them in two parts                                                                                  |
| A Unit folio left with a charge of another room | Check-out still needs the unit folio at 0. See [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md) |

## Tests

- Charges from several folios move in one transfer group; the balances match the preview.
- One bad line refuses the whole move and writes nothing; a replay is refused.
- Closed and Voided folios and payment lines are not offered.
- "Charge room nights" creates the unit + Room rule and the scheduled nights show on the target; "Charge everything" creates both rules and wins over a BillingMode Room rule; a more specific rule keeps its row and the preview says so; a same-selector Manual rule is replaced; BillingMode rules are untouched.
- The posted move and the rules roll back together.
- A CheckedOut unit's folio at 0 closes after the move.
- 200 charges pass, 201 are refused.
- Permission 403 and other-reservation 404.
