# Group check-out

## Purpose

Check out every room of a group and settle its money on one page: collect what each room owes, refund deposits, move charges to the Master folio, take one payment for everyone, and settle the Master to the city ledger. Today a group is checked out one Dialog per room, and the Master folio is found later by the night audit.

It is a full page, not a Dialog: each room needs a few fields, so ten rooms do not fit an overlay, and stacking a room's check-out Dialog over a group Dialog breaks the one-overlay-level rule ([Form containers](../design-system/05-form-containers.md#hard-rules)). A single room keeps the unit **Check out** Dialog. Because this is a page, it can open that Dialog for one room (one overlay level): see [Check out early from the page](#check-out-early-from-the-page).

## Page

A real table (`components/ui/table`) in sections, each with a heading and a count. A section with no rows is not shown.

```
Check out · WA1-0142 · Acme Sdn Bhd                                 [Cancel] [Check out 3 rooms and settle 1]

Ready to check out (3)
┌───┬──────┬──────────────┬──────────┬──────────────────────────────────────────────────────┐
│ ☑ │ Room │ Guest        │  Balance │ Settle                                               │
├───┼──────┼──────────────┼──────────┼──────────────────────────────────────────────────────┤
│ ☑ │ 101  │ Tan Wei Ming │     0.00 │ Nothing to settle                                    │
│ ☑ │ 103  │ Ahmad Faiz   │    45.00 │ (•) Collect [Cash ▾] [ 45.00 ]  ( ) Add to group bill │
│ ☑ │ 104  │ Siti Aminah  │  −200.00 │ Refund deposit [ 200.00 ] to Card ··4411             │
└───┴──────┴──────────────┴──────────┴──────────────────────────────────────────────────────┘

Money to settle (1)
┌───┬──────┬──────────────┬──────────┬──────────────────────────────────────────────────────┐
│ ☑ │ 110  │ Daniel Lim   │    30.00 │ Checked out 11 Oct · (•) Collect [Cash ▾] [ 30.00 ]   │
└───┴──────┴──────────────┴──────────┴──────────────────────────────────────────────────────┘

Departing later (1)
  105 · Wong Kar Wai     Departs 15 Oct                                  [Check out early…]

Can't check out yet (2)
  107 · Priya Nair       Room 106 pays for it: still charged tonight     [Move charges ↗]
  108 · Lim Mei Ling     Not checked in: Cancel or mark no-show.

1 room already checked out with nothing to settle (109).

☐ One payment for all: collect MYR 75.00 in one go from [ Room 103 · Ahmad Faiz ▾ ]

┌ Group bill (Master folio) · Acme Sdn Bhd ─────────────────────────────────────────────────┐
│ Charges the group pays, not the guests.                                                   │
│ Balance now 8,100.00 · Added from rooms +80.00 · After check-out 8,180.00                 │
│ ☐ Send to City ledger after check-out                                                     │
└───────────────────────────────────────────────────────────────────────────────────────────┘

3 rooms check out · 1 settled · Collect 75.00 · Refund 200.00 · Add 80.00 to group bill
```

- **Ready to check out**: the header checkbox ticks or unticks every row in the section.
- **Money to settle**: CheckedOut, Cancelled and NoShow rooms whose folios still hold money. Each has a checkbox and no check-out step.
- **Departing later** and **Can't check out yet**: muted rows with no checkbox, after the tables.
- A room that is out (or cancelled) with nothing to settle is not a row: one muted line names the rooms. Cancelled and no-show rooms get their own line ("1 room cancelled with nothing to settle (111)."). Money to settle shows "Checked out 11 Oct" for a room that is out (from `checked_out_at`, in the property time zone) and "Cancelled" or "No-show" without a date.
- The summary leaves out every part that is 0. With no money at all it reads "2 rooms check out. Nothing to settle."
- On a narrow screen each row is a card with its settle choice, and the summary and the button stay in a sticky footer.
- UI words: **group bill** for the Master folio ("Add to group bill", "Group bill (Master folio)"). Code keeps the Master names.

## Rows

| Unit                                                                                                      | Group               | Ticked          | Settle choices                                                                                                                                   |
| --------------------------------------------------------------------------------------------------------- | ------------------- | --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| CheckedIn, departing on or before the stay day                                                            | Ready to check out  | Yes             | By balance, below. It is checked out                                                                                                             |
| CheckedIn, departing later                                                                                | Departing later     | No checkbox     | "Departs 15 Oct" with **Check out early…** ([below](#check-out-early-from-the-page))                                                             |
| CheckedIn, departing later, arrived on the business date, before midnight                                 | Departing later     | No checkbox     | "Arrived today." with **Check out as day use…**, with `reservations.manage` and `front-desk.check-out`                                           |
| CheckedIn, its folio still receives another room's Scheduled charges (`folio_receives_scheduled_charges`) | Can't check out yet | No checkbox     | "Room 106 pays for it: still charged tonight", with **Move charges** ([Move charges](../13-folio/07-move-charges.md)) only with `folio.transfer` |
| CheckedOut                                                                                                | Money to settle     | Only with money | With money: the choices by balance, and **no check-out step**. At 0: not a row, named in the "already checked out" line                          |
| Confirmed                                                                                                 | Can't check out yet | No checkbox     | Muted: "Not checked in: Cancel or mark no-show."                                                                                                 |
| Cancelled, NoShow                                                                                         | Money to settle     | Only with money | Listed only when its folio holds money (a deposit to refund, a fee owed)                                                                         |

**Choices by balance**, each prefilled:

| Balance of the unit folios | Choice                                                                                                                                                         | What runs                                                                    |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| 0                          | Nothing to settle                                                                                                                                              | –                                                                            |
| Above 0                    | **Collect**: method (the property's first active method, or the room's last payment method), amount = the balance, reference when the method needs one         | `RecordPayment` (Settlement) on the unit folio, as the check-out Dialog does |
| Above 0                    | **Add to Master**: moves the room's open charges to the Master folio (only when the reservation has an Open Master)                                            | `TransferLines`, as [Move charges](../13-folio/07-move-charges.md)           |
| Below 0                    | **Refund**: amount = the credit, back through the held payments (a CheckedIn row too, see [Refund the credit](01-check-in-and-check-out.md#refund-the-credit)) | `RefundPayment`, as the check-out Dialog does                                |

- A unit folio that is **Closed but holds money** (it cannot take a payment) shows "Folio closed: reopen it to settle" and **Open folio**. Front desk can reopen a unit folio closed today; after that a manager can (`FolioReopenRule`).
- A choice the user may not make is not offered: Collect needs `payment.record`, Refund `payment.refund`, Add to Master `folio.transfer`.

## Night audit pending

While the [night audit is pending](01-check-in-and-check-out.md#night-audit-pending), a room that departs on the calendar date is **Ready to check out**, not "departing later". Its row balance and its settle amounts include the night of the business date, and the row lists those lines for the page to show as added now. `CheckOutGroup` posts that night for each room first, before it moves charges to the group bill or settles money, so Add to group bill moves the night too. The night audit later finds the lines and posts nothing.

A room that was due to leave on the business date stays ready as "They left yesterday". Its note reads "Due to leave yesterday (20 Oct). If they stayed last night, check this room out on its own." The page has no "They stayed" choice. An Overdue audit keeps the old note and blocks **Check out early…**.

## Check out early from the page

A room departing after the business date, and not arrived on it, has **Check out early…** (with `front-desk.check-out`). It opens the room's existing **Check out** Dialog (`CheckOutDialog`), which shows the early departure fee, the nights removed and **Do not charge** (`front-desk.waive-fees`), and runs `EarlyCheckOut`.

- It is its **own save**, not part of the group save: the fee is confirmed or waived per room, and one refused fee must not undo the group.
- The page sends the unit data and payment options the Dialog needs for these rows, as the reservation page does.
- After it saves, the page asks for the preview again. The room moves to **Money to settle** when its folio still holds money (for example the fee), else into the "already checked out" line. The rows the user already ticked and filled stay as they were.
- A same-day arrival opens the [day use check-out Dialog](01-check-in-and-check-out.md#check-out-as-day-use) instead, with the note **Arrived today.** It saves separately and keeps the other row choices. After midnight, normal or early check-out applies. The server sends `checkOutAction` with its key and label, and `unit` only when the user can open that action.

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

- The **Group bill (Master folio)** box shows the payer's name (else the reservation's group name), the line "Charges the group pays, not the guests.", the balance now, what this check-out adds and the balance after.
- The box shows only when the Master has a balance, a row is set to Add to group bill, or the City ledger option applies.
- **Send to City ledger after check-out** (settles the Master, default off) shows for a company payer with direct bill and `city-ledger.invoice`. It runs `SettleToCityLedger` after every room is out, in the same save. Without the box, the success page offers **Settle to City ledger** or **Open folio**.
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
- **No room to check out**: when the preview has no row (every room is out or cancelled with nothing to settle) and no Open Master or Third party folio still holds money, the page redirects to the reservation page with an info toast, "No rooms are left to check out." An existing toast, for example the one a settle just flashed, is kept. The page after a save is the exception: it keeps the success state. See [Folios still open](#folios-still-open).
- **After saving**: the store redirects to the same page with Inertia flash data `groupCheckOut` (`GroupCheckOutDoneData`: rooms checked out and settled, collected, refunded, moved, the invoice number, the payment ids of the save and the Master link). The page shows a success state with the totals, **Print receipts** (opens one PDF with every receipt of this save in a new tab, built from the payment ids by `reservations.check-out.receipts`; shown only with payment.record and at least one payment) and the Master next step: **Settle to City ledger** while the Master is Open and the user may settle it, else **Open folio** (both open the Master folio tab).

## Folios still open

Each **Check out early…** is its own save, so the last room can leave without the group save, and the **Send to City ledger after check-out** box never shows. When every room is out but an Open Master or Third party folio still holds money, the page does not redirect. It shows "All rooms are checked out" and the list **Folios still open**.

| Booking                                              | Listed                                                          | Redirects with the toast         |
| ---------------------------------------------------- | --------------------------------------------------------------- | -------------------------------- |
| Each unit pays                                       | nothing: no charge is routed to its Master, so the balance is 0 | yes                              |
| Master pays, company on direct bill                  | the Master                                                      | no                               |
| Master pays, no company                              | the Master                                                      | no                               |
| Any of the above plus a Third party folio with money | the Master first, then each Third party folio                   | no                               |
| A folio at 0, Closed or Voided                       | not listed                                                      | yes, when nothing else is listed |

- **List only when no room is left.** `GroupCheckOutPreview` builds `openFolios` (`GroupCheckOutFolioData`) only when `rows` is empty, so the 300 ms preview of a busy page pays nothing for it.
- **Row.** The folio label (type and payer), the amount to pay or in credit, and the actions below.
- **Record payment.** Opens `RecordPaymentDialog`, the folio tab's Dialog. Shown with `payment.record` and a purpose the folio offers (`PaymentPurposeChoices`). The amount starts at the balance.
- **Settle to City ledger.** Opens `SettleCityLedgerDialog`, the folio tab's Dialog. Shown for a company payer with direct bill, a balance above 0 and `city-ledger.invoice`. `settleCreditWarning` shows in the Dialog when the company is over its limit. Settle keeps its own refusals (`units_in_house`, scheduled charges still routed to the folio).
- **Open folio.** The folio tab in a new tab (`target="_blank"`), so the page and its state stay.
- **After an action** the Dialog posts and redirects back to this page, which lists the folios again. When the last folio is settled the page redirects with the toast of that action.
- **Back from the other tab.** The page reads its data again when the browser tab becomes visible, so a payment taken on the folio tab shows here.
- **Refund** is not offered here. A folio in credit shows "In credit" and Open folio. Decided: staff refund it with **Refund credit** on the folio tab ([Refund folio credit](../14-payments/01-payments-and-refunds.md#refundfoliocredit)), which works on a Master or ThirdParty folio too, to the payments' own methods or, with `payment.refund-other-method`, another one.
- The two Dialogs take the fields they read (`Pick<FolioData, ...>`), so the page that has only a `GroupCheckOutFolioData` can open them. The folio tab still passes its full `FolioData`.

## Routes and entry

| URL                                                  | Name                              | Does                                                                                                        |
| ---------------------------------------------------- | --------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `GET /p/{code}/reservations/{reservation}/check-out` | `reservations.check-out`          | The page                                                                                                    |
| `POST …/check-out/preview` (JSON)                    | `reservations.check-out.preview`  | Rows, balances and the summary, writes nothing                                                              |
| `POST …/check-out`                                   | `reservations.check-out.store`    | `CheckOutGroup`                                                                                             |
| `GET …/check-out/receipts?payments[]=…`              | `reservations.check-out.receipts` | One PDF with the receipts of the save (`payment.record`); an id of another reservation or a refund is a 404 |

| Entry                                                                                                                            | Goes to                                  |
| -------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| Reservation page **⋯ More › Check out all** (2 or more units, one CheckedIn or holding money; the server sends `canCheckOutAll`) | The page                                 |
| Night audit **Not checked out** group row (a **Check out all** link next to **Extend 1 night**)                                  | The page (a link; the sheet closes)      |
| Dashboard To do group departure row (added with the dashboard item)                                                              | The page                                 |
| One room                                                                                                                         | Unchanged: the unit **Check out** Dialog |

## Edge cases

| Case                                                                  | Behaviour                                                                                                    |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| A room left early and a late charge was posted after (reopened folio) | Under Money to settle with its balance: Collect, Add to Master or One payment for all; the folio closes at 0 |
| A charge reversed after check-out leaves a credit                     | A Refund row                                                                                                 |
| A cancelled room holds a deposit                                      | Under Money to settle: Refund. This also clears the audit's **Money held on a closed booking** warning       |
| The hotel writes off a late charge                                    | Not a settle choice: reverse the line on the folio tab                                                       |
| One row is refused                                                    | Nothing is saved; the row shows why                                                                          |

## Tests

- Ready rooms check out; Collect, Refund and Add to Master post as the single Dialog would; One payment for all moves the balances and records one payment. A row whose folio holds a deposit refunds it with the move; without `payment.refund` the move is not offered.
- A CheckedOut room with a late charge is settled without a second check-out and its folio closes.
- One refused row saves nothing, also when the refusal comes from inside `CheckOutAndRefund`. A double click records each payment once.
- Settle Master runs only after every room is out; it is refused while a live unit routes Scheduled charges to the Master.
- Disabled rows (departs later, charged by another room, not arrived) are never touched.
- Permissions per choice; 404 across properties.
