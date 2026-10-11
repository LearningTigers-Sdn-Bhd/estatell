# Group check-out

## Purpose

Check out every room of a group and settle its money on one page: collect what each room owes, refund deposits, move charges to the Master folio, take one payment for everyone, and settle the Master to the city ledger. Today a group is checked out one Dialog per room, and the Master folio is found later by the night audit.

It is a full page, not a Dialog: each room needs a few fields, so ten rooms do not fit an overlay, and stacking a room's check-out Dialog over a group Dialog breaks the one-overlay-level rule ([Form containers](../design-system/05-form-containers.md#hard-rules)). A single room keeps the unit **Check out** Dialog.

## Page

```
Check out · WA1-0142 · Acme Sdn Bhd                      [Cancel] [Check out 6 rooms and settle 1]
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Room        Guest           Balance   Settle                                              │
│ ☑ 101       Tan Wei Ming      0.00    Nothing to settle                                   │
│ ☑ 103       Ahmad Faiz       45.00    (•) Collect  [Cash ▾] [ 45.00 ]                     │
│                                       ( ) Add to Master · Acme Sdn Bhd                    │
│ ☑ 104       Siti Aminah    −200.00    Refund deposit [ 200.00 ] to Card ··4411            │
│ ☑ 106       Raj Kumar        80.00    ( ) Collect  (•) Add to Master · Acme Sdn Bhd       │
│ ☐ 105       Wong Kar Wai               Departs 15 Oct: use Check out for an early check-out │
│ ☐ 107       Priya Nair                 Room 106 pays for it: still charged tonight [Move charges ↗] │
│ ─ Already checked out ─────────────────────────────────────────────────────────────────── │
│   109       Nur Aisyah        0.00    Checked out 11 Oct (early) · folio closed           │
│ ☑ 110       Daniel Lim       30.00    Checked out 11 Oct                                  │
│                                       (•) Collect [Cash ▾] [ 30.00 ]  ( ) Add to Master   │
│                                                                                           │
│ ☐ One payment for all: collect MYR 75.00 in one go from [ Room 103 · Ahmad Faiz ▾ ]        │
│ Master · Acme Sdn Bhd   8,100.00 + 80.00 moved = 8,180.00                                 │
│ ☐ Settle Master to City ledger after check-out                                            │
│ Collect 75.00 · Refund 200.00 · Move 80.00 to Master · 4 rooms out · 1 settled            │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

On a narrow screen each room is a card with its settle choice, and the summary and the button stay in a sticky footer.

## Rows

| Unit                                                                                                      | Group               | Ticked          | Settle choices                                                                                                        |
| --------------------------------------------------------------------------------------------------------- | ------------------- | --------------- | --------------------------------------------------------------------------------------------------------------------- |
| CheckedIn, departing on or before the business date                                                       | (main)              | Yes             | By balance, below. It is checked out                                                                                  |
| CheckedIn, departing later                                                                                | (main)              | Disabled        | "Departs 15 Oct: use Check out for an early check-out."                                                               |
| CheckedIn, its folio still receives another room's Scheduled charges (`folio_receives_scheduled_charges`) | (main)              | Disabled        | "Room 106 pays for it: still charged tonight", with **Move charges** ([Move charges](../13-folio/07-move-charges.md)) |
| CheckedOut                                                                                                | Already checked out | Only with money | Its folio at 0 and Closed: a muted line, nothing to do. With money: the choices by balance, and **no check-out step** |
| Confirmed                                                                                                 | Not arrived         | Not ticked      | Muted: "Not checked in: Cancel or mark no-show."                                                                      |
| Cancelled, NoShow                                                                                         | Cancelled           | Only with money | Listed only when its folio holds money (a deposit to refund, a fee owed)                                              |

**Choices by balance**, each prefilled:

| Balance of the unit folios | Choice                                                                                                                                                 | What runs                                                                    |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------- |
| 0                          | Nothing to settle                                                                                                                                      | –                                                                            |
| Above 0                    | **Collect**: method (the property's first active method, or the room's last payment method), amount = the balance, reference when the method needs one | `RecordPayment` (Settlement) on the unit folio, as the check-out Dialog does |
| Above 0                    | **Add to Master**: moves the room's open charges to the Master folio (only when the reservation has an Open Master)                                    | `TransferLines`, as [Move charges](../13-folio/07-move-charges.md)           |
| Below 0                    | **Refund**: amount = the credit, back through the held payments                                                                                        | `RefundPayment`, as the check-out Dialog does                                |

- A unit folio that is **Closed but holds money** (it cannot take a payment) shows "Folio closed: reopen it to settle" and **Open folio**. Front desk can reopen a unit folio closed today; after that a manager can (`FolioReopenRule`).
- A choice the user may not make is not offered: Collect needs `payment.record`, Refund `payment.refund`, Add to Master `folio.transfer`.

## A folio that holds payments

`TransferLines` moves whole charges, and payments never move. A folio with payments (a security deposit, tourism tax collected at check-in) would keep a credit after its charges move, and could not close. Example: room 103 has 95.00 in charges and a 50.00 deposit, so its balance is 45.00. Moving the 95.00 leaves −50.00.

So **Add to Master** and **One payment for all** move every open charge of the row and, in the same save, refund the payments the folio holds, through `RefundPayment` (oldest first, as the check-out Dialog does). The row says it: "Moves 95.00 · refunds 50.00 deposit".

| Case                                                                                              | Behaviour                                                                      |
| ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| The folio holds no payments                                                                       | The move only                                                                  |
| The folio holds payments, the user has `payment.refund` and every payment can be refunded         | The move and the refund                                                        |
| The user lacks `payment.refund`, or a payment cannot be refunded (for example a City ledger line) | Add to Master and One payment for all are not offered on the row: Collect only |

## One payment for all

The group leader often pays everyone's extras. With **One payment for all** ticked, the page asks for the room that pays. The Collect amounts of the ticked rows (CheckedOut rows included) move to that room's folio, and one payment is recorded there: one receipt instead of six. Rows set to Add to Master or Refund are not part of it. A row whose folio holds payments refunds them, as in [A folio that holds payments](#a-folio-that-holds-payments).

## The Master folio

- A line shows the Master's balance and what this check-out moves to it.
- **Settle Master to City ledger after check-out** (default off) shows for a company payer with direct bill and `city-ledger.invoice`. It runs `SettleToCityLedger` after every room is out, in the same save. Without the box, the success page offers **Settle to City ledger** or **Open folio**.
- Settle keeps its own rules: refused while a unit is still in house (`units_in_house`) or while a live unit still routes Scheduled charges to it.

## `CheckOutGroup`

`(User $actor, Reservation $reservation, list<GroupCheckOutRow> $rows, ?OnePayment $onePayment, bool $settleMaster): CheckOutGroupResult` in `app/Actions/FrontDesk/`.

1. The moves: Add to Master rows and One payment for all, through `TransferLines`, each with the refund of the payments its folio holds.
2. Each row: `CheckOutAndRefund` with its collect and refund data for a CheckedIn unit, or only the payment, refund or move for a CheckedOut, Cancelled or NoShow unit (never `CheckOutUnit` again).
3. With `$settleMaster`, `SettleToCityLedger` on the Master.

- **One transaction, all or nothing.** `CheckOutAndRefund` opens its own transaction, which becomes a savepoint here and rolls back only itself on a refusal, so `CheckOutGroup` checks every step's result and rolls back the whole save on the first refusal. Money is involved, and a half-done save (some receipts issued, some rooms still in) is hard to undo. A refusal saves nothing and shows on its row: "Room 108: the card payment needs a reference."
- Each payment and refund uses `{uuid}:{unit}:collect` and `{uuid}:{payment id}` keys, as the check-out Dialog does, and One payment for all uses `{uuid}:all`, so a double click records once (a repeat of a save finds its payments and refunds and adds none).
- One payment for all carries the amount the page showed. The server adds up what it moves to the paying room (plus the payer's own balance when its row is Collect) and refuses with `amount_changed` when the total differs. The paying room is a CheckedIn or CheckedOut row with an open folio, set to Collect or nothing.
- A refusal comes back as the room (unit id) and a code, and shows as an error keyed `units.{id}`. A CheckedOut, Cancelled or NoShow room that was settled must end with its folios at 0, else `balance_not_settled`.
- Server-side checks of the choices: Collect `payment.record`, Refund `payment.refund`, Add to Master and One payment for all `folio.transfer`, and a move of a folio that holds payments `payment.refund`. Otherwise the save is a 422 on the room.
- Every unit keeps its own log entries, room Dirty, folio close and status sync, as today. One `reservation.group_checked_out` entry holds the totals.
- A CheckedOut unit's folio left at 0 closes itself (`CloseSettledUnitFolio`).
- Permission: `front-desk.check-out` for the page, plus the money permissions above.
- **After saving**: a success state with the totals, **Print receipts** (one PDF with every receipt of this save) and the Master next step.

## Routes and entry

| URL                                                  | Name                             | Does                                           |
| ---------------------------------------------------- | -------------------------------- | ---------------------------------------------- |
| `GET /p/{code}/reservations/{reservation}/check-out` | `reservations.check-out`         | The page                                       |
| `POST …/check-out/preview` (JSON)                    | `reservations.check-out.preview` | Rows, balances and the summary, writes nothing |
| `POST …/check-out`                                   | `reservations.check-out.store`   | `CheckOutGroup`                                |

| Entry                                                                                         | Goes to                                  |
| --------------------------------------------------------------------------------------------- | ---------------------------------------- |
| Reservation page **⋯ More › Check out all** (2 or more units, one CheckedIn or holding money) | The page                                 |
| Night audit **Not checked out** group row                                                     | The page (a link; the sheet closes)      |
| Dashboard To do group departure row (added with the dashboard item)                           | The page                                 |
| One room                                                                                      | Unchanged: the unit **Check out** Dialog |

## Edge cases

| Case                                                                  | Behaviour                                                                                                        |
| --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| A room left early and a late charge was posted after (reopened folio) | Under Already checked out with its balance: Collect, Add to Master or One payment for all; the folio closes at 0 |
| A charge reversed after check-out leaves a credit                     | A Refund row                                                                                                     |
| A cancelled room holds a deposit                                      | Under Cancelled: Refund. This also clears the audit's **Money held on a closed booking** warning                 |
| The hotel writes off a late charge                                    | Not a settle choice: reverse the line on the folio tab                                                           |
| One row is refused                                                    | Nothing is saved; the row shows why                                                                              |

## Tests

- Ready rooms check out; Collect, Refund and Add to Master post as the single Dialog would; One payment for all moves the balances and records one payment. A row whose folio holds a deposit refunds it with the move; without `payment.refund` the move is not offered.
- A CheckedOut room with a late charge is settled without a second check-out and its folio closes.
- One refused row saves nothing, also when the refusal comes from inside `CheckOutAndRefund`. A double click records each payment once.
- Settle Master runs only after every room is out; it is refused while a live unit routes Scheduled charges to the Master.
- Disabled rows (departs later, charged by another room, not arrived) are never touched.
- Permissions per choice; 404 across properties.
