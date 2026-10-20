# Manual Folio Editor

## Purpose

Let a manager settle a folio by hand: add charges and payments that were missed, with the day they really happened, and fix lines, in one page with one reason. The page is called the **Manual Folio Editor**. The permission, the routes, `folio_manual_batches` and the activity events keep their `manual` names.

Hotels ask for this when a stay was run on paper, entered late, or moved from another PMS. wastays did it with a **Posting date** field and an `override_night_audit` switch that wrote lines into closed business dates. estatell never writes into a closed date. It keeps two dates on each line instead.

## Two dates on a line

| Column               | Meaning                                                                 | Rule                                                                                        |
| -------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `posting_date`       | The business date the line counts in: revenue, tax, cash-up, statistics | Always the current business date, as today. Closed dates never change.                      |
| `service_date` (new) | The day it really happened                                              | Not null. Equal to `posting_date` unless a manual line, a night or a migrated line sets it. |

- `service_date` is never after `posting_date`. A database CHECK enforces it.
- The folio tab, the Manual Folio Editor, the voucher and the folio invoice show and sort by `service_date`. A row whose `service_date` is before its `posting_date` adds a muted second line, "posted {posting date}".
- Reports keep counting by `posting_date`. The Folio ledger report gains a hidden-by-default **Service date** column. The Payments report's cash-up shows the service date next to the posting date, so a cashier can match the drawer.
- `PostRoomCharges` sets `service_date` to the night. A Retry or a catch-up night then sorts on its own night, and the description no longer needs "night of {date}" (it keeps it for the ledger report).
- Child lines (tax, discount) and reversals copy the `service_date` of their parent or original line. A transfer copy keeps it too.

## Entry

On the folio tab, the **More actions** dropdown gains a **Manual** group with **Manual Folio Editor…**. It shows only on an Open folio (a Closed folio is reopened first, with the existing rules) and only to users with `folio.manual`.

```
┌─ Manual Folio Editor ───────────────────────────────────┐
│  Lines you add post today (10 Oct) and keep the date    │
│  you enter as their service date.                       │
│                                                         │
│  Reason *                                               │
│  ┌───────────────────────────────────────────────────┐  │
│  │ Choose a reason                                 ▾ │  │
│  └───────────────────────────────────────────────────┘  │
│  Reason details *              (only for Other)         │
│  ┌───────────────────────────────────────────────────┐  │
│  │ e.g. Guest paid cash to the night staff           │  │
│  └───────────────────────────────────────────────────┘  │
│                               [Cancel]  [Continue]      │
└─────────────────────────────────────────────────────────┘
```

The reason is a select of `ManualFolioReason`, in this order:

| Value            | Label                                 |
| ---------------- | ------------------------------------- |
| `paper_folio`    | Entering charges from the paper folio |
| `missed_charge`  | A charge was missed                   |
| `missed_payment` | A payment was not recorded            |
| `old_system`     | Moving from the old system            |
| `fix_mistake`    | Fixing a mistake                      |
| `other`          | Other                                 |

- Nothing is picked at first. **Continue** stays disabled until a reason is picked, and for Other until details are typed.
- **Other** shows **Reason details** (required, at most 500 characters).
- The requests send `reason_option` and `reason_details`. The server builds the reason text: the label, or the trimmed details for Other. The batch keeps that text in `folio_manual_batches.reason`, so the Manual marker and the Activity log read as before.
- **Edit** on the page reopens the dialog on the option whose label matches the reason, else on Other with the text as details.

**Continue** opens the editor page (`POST …/manual/start` flashes the reason, then redirects to the page). The reason is kept in the page's form state and saved with the first post. Nothing is written until the first post. A page opened without a reason (a reload or the address) opens the reason dialog first.

## Page

A full page with the same shell as the [reservation page](../12-reservations/06-reservation-page.md): the `fixed` layout variant and `ReservationWorkspace`, three rounded panels that scroll on their own. On the left is the folio rail, in the centre are the lines, and on the right is the summary. `FormActions` stays visible at the bottom. Below `lg` the panels stack, the rail becomes a select and the rows stack into cards.

```
┌─ Reservations › GRH-26300118 › Folio › Manual Folio Editor ──────────────────────────────────────────┐
│ Manual Folio Editor                                                                              │
│ Reason: Entering charges from the paper folio  [Edit]                                            │
│ ┌──────────────┐ ┌──────────────────────────────────────────────────────────────┐ ┌────────────┐ │
│ │ Master       │ │ New lines (3)   Posted lines (14)   Scheduled (2)        [⇥] │ │ SUMMARY    │ │
│ │ Ahmad Faiz   │ │ Type    │Date ⓘ │Item / method│Description│Qty│Amount│Taxes  │ │   Now After│ │
│ │ Open  540.00 │ │ Charge ▾│08 Oct▾│Minibar ▾    │e.g. 2 Coke│ 2 │  6.00│SST 8%▾│ │ Charges    │ │
│ │ 2 new        │ │ Charge ▾│09 Oct▾│Laundry ▾    │e.g. Shirts│ 1 │ 25.00│None  ▾│ │ Payments   │ │
│ ├──────────────┤ │                                             [+ Add line]     │ │ Balance    │ │
│ │ Room 103     │ │                                                              │ │            │ │
│ │ Open    0.00 │ │                                                              │ │ [✓] Close  │ │
│ │ 1 new        │ │                                                              │ │ the folio  │ │
│ └──────────────┘ └──────────────────────────────────────────────────────────────┘ └────────────┘ │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                   [Discard]  [Post 3 lines]      │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

| Header | `PageHeader` with a breadcrumb back to the folio tab. The reason sits under the title. **Edit** reopens the reason dialog. The posting rule ("Lines post today and keep the date you enter.") is the tooltip of the Date column header. |
| Tabs | `Tabs`, `line` variant, an icon in each, at the top of the centre panel. **New lines** opens first. **Posted lines** is `FolioRowsTable` of the selected folio, read only, with the row menu below. **Scheduled** lists the Scheduled rows of the selected folio, read only with no row menu ([Scheduled lines](#scheduled-lines)); it shows only when the folio has some. These are client tabs inside one form, not page tabs. The Hide summary / Show summary button sits at the right of the tabs: the same button and the same remembered choice as on the folio tab. |
| New lines | The add-and-remove rows table ([Form containers](../design-system/05-form-containers.md)): **+ Add line** in `TableFooter` on the right, a trash icon to remove. One row per line. |
| Type | Charge, Payment or Refund. Payment rows show Method, Purpose and Reference in place of Item, Qty and Taxes ([Payment purpose](#payment-purpose)). A Refund row shows a **Payment** picker (a refundable payment on the selected folio) in place of Method, and its amount cannot exceed what is still refundable on that payment. |
| Date | The service date. From the reservation's creation date (a deposit before arrival) to the business date. Never before the property's first business date, except with the **Old system** method ([Migration](../17-onboarding/07-migration.md#old-system-payment-method)). |
| Item | A charge item, as in the Post charge Dialog. The amount follows `default_amount` and `allow_override`. |
| Taxes | A `MultiCombobox`, the same field as on the charge item page (`taxLabel()`). It starts with the charge item's own taxes, and picking another item resets it. A choice that differs from the item shows ✎ with the tooltip "Taxes changed from the item's default". |
| Total | Includes tax. Its ⓘ popover lists each tax and its amount, like `BookingQuoteCell` on the booking form. A foreign-only tax for a local guest shows "Exempt" and 0.00: the nationality rule still applies. |
| Folio rail | `FolioRail` with the Open folios of the reservation (name, payer, status, balance now). Picking one changes the selected folio and the address (`…/folios/{folio}/manual`) without the unsaved-changes dialog. Draft rows are kept for every folio, and each folio with drafts shows "N new". There is no Folio column: a row posts on the folio it was added to. **+ Add folio** at the bottom adds a Third party folio ([Add a Third party folio](#add-a-third-party-folio)). |
| Room | Charge rows only. `PostCharge` works on a unit (its status and its guest's nationality decide the tax). A Unit folio has no Room column: the folio fixes the unit. On another folio a Room column shows, preselected when the reservation has one postable unit. The unit must be Checked in or Checked out, else the row is refused with `unit_not_postable`. |
| Summary | The right panel. Charges, payments and balance of the selected folio, **Now** and **After**, plus a balance for each folio the rows touch. Every figure comes from the server preview. The summary button hides it. Below `lg`, hiding removes it. |
| Close after posting | A checkbox in the summary, for the selected folio. Ticked by default when its balance after posting is 0.00. Disabled with the `CloseFolio` reason as a hint when the folio cannot close (`units_in_house`, `folio_receives_scheduled_charges`). |
| Footer | **Post N lines** posts the draft rows of every folio as one batch with one reason. **Discard** asks to confirm when there are draft rows. Leaving the page with drafts asks too (`useUnsavedChanges`). |
| Errors | Inline on the row through `FormField`, focus on the first refused row. Nothing posts. |

## Payment purpose

A Payment row has a **Purpose** select: Advance, Settlement or Security deposit. It is the `purpose` of the payment, as in Record payment ([Payments](../14-payments/01-payments-and-refunds.md)).

- The options come from the folio type, not the stay stage, because staff enter old payments here (`PaymentPurpose::forManualFolio()`): a Unit folio offers Settlement, Advance and Security deposit, a Master folio Settlement and Advance, a Third party folio Settlement only. Each folio of `ManualFolioPageData` carries its `purposes`.
- The first option is the default. A refund copies the purpose of the payment it refunds, so a Refund row has no Purpose.
- `PostManualBatch` passes the purpose to `RecordPayment`, which keeps its refusals (Security deposit only on a Unit folio, Advance not when the rooms go to the Master folio) and adds `purpose_not_offered` (a Third party folio takes Settlement only). `ManualFolioPreview` gives the same refusals on the row.

## Scheduled lines

The **Scheduled** tab shows what the night audit will still post on the selected folio, so staff see the whole folio before they fix it. It is the same data as the Scheduled rows of the folio tab, in `FolioRowsTable`, read only: no row menu, no editing. To change a scheduled night's price, use **Change price** on the Units tab. Scheduled lines are never part of the batch and are never posted from this page.

## Add a Third party folio

**+ Add folio** at the bottom of the rail opens a Dialog: **Payer type** (Company or Guest) and one **Payer** picker. It is the same Dialog as on the folio tab (`ThirdPartyFolioDialog`), posting to `POST …/folios/{folio}/manual/folios` (`reservations.folios.manual.folios.store`). It calls `CreateFolio::thirdParty` with the actor, so it is logged on the reservation like on the [routing](03-routing.md) page. The new folio is Open, joins the rail and the page opens on it. The draft rows stay: the page keeps its state, like when the rail switches folio. A company payer needs `company.view`, as on the folio tab. A Unit folio is never made here: `PostCharge` makes it on the first posting for a unit. It needs `folio.manual`.

## Move charges link

**Move charges…** sits at the right of the tabs on **Posted lines**, for a user with `folio.transfer` when the reservation has another Open folio (`FolioData.canMoveCharges`). It opens the [Move charges](07-move-charges.md) page with the selected folio as the target. The editor's unsaved-changes dialog asks first when there are draft rows. The Move page returns to the folio tab, not to the editor.

## Preview and post

- `ManualFolioPreview` (`app/Services/Folio/`) takes all the draft rows and returns each row's tax lines, total and refusal, and the rail figures summed on the server. The page calls it through a `useHttp` request when a row changes (the same pattern as the booking form quote) and updates only the changed row's cell, plus the rail. It writes nothing.
- `PostManualBatch` (`app/Actions/Folio/`) takes the actor, the folio, the reason, the rows and the close flag. In one transaction, with every touched folio locked in id order:
    1. Creates the `folio_manual_batches` row.
    2. Posts each row in order through the existing Actions: a charge through `PostCharge` (with `folio_id`, `service_date`, `tax_ids` and `manual_batch_id`), a payment through `RecordPayment`, a refund through `RefundPayment`.
    3. Runs `CloseFolio` when the flag is on.
- Any refusal rolls back everything, including the batch row and receipt numbers, and names the row.
- `PostCharge` and `RecordPayment` take two new optional inputs: `service_date` (checked against the range above, refusal `service_date_invalid`) and `manual_batch_id`. `PostCharge` also takes an optional `tax_ids` list, used only from this page: `TaxCalculator` runs for the chosen taxes instead of the item's own.
- The form's UUID is the batch key. A repeated submit returns the existing batch and writes nothing.
- Logged on the reservation: `folio.manual_batch_posted` with the batch id, the reason and the number of lines.

## Row menu on posted lines

On the **Posted lines** tab, and on the **folio tab** of an Open folio for a user with `folio.manual`, each top-level row's ⋯ menu adds manual actions to the existing ones:

```
│  09 Oct   Laundry · Shirts      1     25.00   ⋯ ┐
│                                  ┌──────────────┴───┐
│                                  │ Edit line…       │
│                                  │ Reverse          │
│                                  │ Split…           │
│                                  │ ──────────────── │
│                                  │ Remove tax…      │
│                                  │ Add tax…         │
│                                  │ Recalculate tax  │
│                                  └──────────────────┘
```

Each one posts at once in its own Dialog (they are not draft rows). The Dialog's reason field starts with the page's reason and can be edited. Every action creates its own `folio_manual_batches` row (its key is the Dialog's UUID, so a second click writes nothing), so each line still points to a reason. After an action, the Posted lines tab, the rail and the summary reload and the draft rows stay.

The menu shows on a live charge. On the editor page **Move**, **Refund**, **Remove discount** and **Correct price** are left out: they leave the page or need data it does not load. Move has its own page ([Move charges](07-move-charges.md)), so the editor links to it instead (see [Move charges link](#move-charges-link)). **Edit line** replaces Correct price there. On the folio tab every item stays and the manual ones follow Move, with no page reason, so the Dialog's reason starts empty. The folio tab loads the folios and taxes the Dialogs pick from (`manualFolios`, `manualTaxes`) when the first Dialog opens. A charge with a live discount offers **Reverse** only: remove the discount first (`line_has_discount`).

| Action          | Dialog                                                                                                                                        | Writes                                                                                                                                                                                                                                                                                  | Refusals                                                                                                                                                                       |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Edit line       | Description, Rate, Quantity and Taxes with the current values, a reason, and a live Before / After from the preview ([Edit line](#edit-line)) | `EditLine`, or the Change price routes for a live room night                                                                                                                                                                                                                            | `nothing_changed`, `line_has_discount`, `folio_not_open`, `line_is_child`, `payment_line`, the `TaxCalculator` refusals, the `ChangeNightPrice` refusals                       |
| Split           | Two to 20 rows of folio and amount, **+ Add folio**, the amounts must add up to the line's entered amount (unit amount × quantity)            | `SplitLine`: a reversal of the line and its children, then one charge per row on its folio, with the same item, description, source and service date, quantity 1, one `transfer_group_id`. The tax of each row is calculated again on its own amount with the taxes the charge has now. | `split_invalid`, `split_total_mismatch`, `folio_not_open`, `different_reservation`, `line_not_transferable`, `payment_line`, `line_has_discount`, the `TaxCalculator` refusals |
| Remove tax      | `MultiCombobox` of the live tax lines on the charge, a reason                                                                                 | `RemoveLineTax`: a reversal of each chosen tax child line only, with no parent. The charge stays.                                                                                                                                                                                       | `tax_not_on_line`, `already_reversed`, `folio_not_open`, `line_has_discount`                                                                                                   |
| Add tax         | `MultiCombobox` of the enabled, non-inclusive taxes not on the charge, a reason                                                               | `AddLineTax`: a new tax child under the charge, calculated by `TaxCalculator` on the charge's net amount and quantity                                                                                                                                                                   | `tax_not_available`, `tax_already_on_line`, `tax_inclusive`, the `TaxCalculator` refusals, `folio_not_open`, `line_has_discount`                                               |
| Recalculate tax | A reason (the new tax lines are the result)                                                                                                   | `RecalculateLineTax`: reverses the current tax children and posts fresh ones with the item's own taxes and today's nationality                                                                                                                                                          | `tax_unchanged`, `net_amount_changed`, the `TaxCalculator` refusals, `folio_not_open`, `line_has_discount`                                                                     |

- **The `line_is_child` rule changes.** `ReverseLine` still refuses a child line on its own. `RemoveLineTax` and `RecalculateLineTax` are the only paths that reverse a **tax** child without its parent. A discount child still reverses only with its parent.
- **Split and tax.** Each part is calculated on its own, so the tax of the parts can differ from the old tax by a cent. A flat tax is charged once for the line, so it goes to the first part only, for the whole quantity. A discount on the charge blocks all four actions because its own tax lines would no longer match.
- **Add tax** refuses a tax that is part of the price (`tax_inclusive`): it would change the net amount, and the charge line never changes. **Recalculate tax** refuses (`net_amount_changed`) when the item's taxes would change the net amount.
- Keys: the batch key is the Dialog's UUID. Lines: `split:{line_id}:{part}`, `tax-add:{line_id}:{tax_id}:{n}`, `tax-recalc:{line_id}:{n}:tax:{tax_id}`, where n counts the earlier ones on that line. A reversal is `reverse:{line_id}`, so a tax line reverses once.
- Logged on the reservation: `folio.line_split`, `folio.line_tax_removed`, `folio.line_tax_added`, `folio.line_tax_recalculated`, each with the batch id and the reason.
- **A room night's tax.** Remove tax fixes only that night. The audit charges the next nights with the normal taxes. A real exemption for a whole stay is a later item (see the [checklist](../checklist.md#later)).
- The tax report counts these on their posting date, like any reversal.

## Edit line

One Dialog for the line, so staff change what is wrong and do not pick between reverse, tax and price actions. **Edit line…** is the first item of the row menu of a live charge with no live discount, on the editor and on the folio tab.

```
┌─ Edit line · Room charge ───────────────────────────────┐
│ Description   [ Room charge · Night 20 Oct            ] │
│ Rate          [ 990.00 ]        Qty [ 1 ]               │
│ Taxes         [ SST 8% ✕ ][ Tourism tax ✕ ]             │
│ Reason *      [ e.g. Guest paid cash to the night staff ] │
│ Before   990.00 + tax 79.20 = 1,069.20                  │
│ After    870.00 + tax 69.60 =   939.60   (−129.60)      │
│                              [Cancel]  [Save changes]   │
└─────────────────────────────────────────────────────────┘
```

| Line                                                                    | Fields                                                                                                                                                                                                  | What saves                                                                                                                                                                                                                                                                                                                                                                         |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A live room night (key `night:{unit_night_id}` or `night:{id}:fix:{n}`) | **Price per night** only. Description, Qty and Taxes show read only. The price is read only too without `reservations.change-price`. The Dialog shows the **Difference** from the Change price preview. | The existing Change price routes (`reservations.units.night-price.preview` and `reservations.units.night-price`) for that one night ([Change a night's price](../12-reservations/09-change-night-price.md)). It sets `unit_nights.amount`, so the Units tab, the stay total and the booking summary agree with the folio. It writes no manual batch and logs as Change price does. |
| Any other top-level charge (migrated, manual, add-on, minibar)          | **Description**, **Rate**, **Qty**, **Taxes** (the same `MultiCombobox` as on a new row, with the property's enabled taxes). The charge item is fixed.                                                  | `EditLine` (`app/Actions/Folio/`): one reversal of the line and its children, then one new charge with the same item, source, service date and `migrated` flag and the new values. No unit price exists, so nothing else changes.                                                                                                                                                  |

- An inclusion line of a night (`night:{id}:incl:…`) has no Edit line: change the price on the night's room line.
- **Before / After** come from `EditLinePreview` (`app/Services/Folio/`), the same checks and tax calculation `EditLine` runs (`plan()`), through `POST …/lines/{line}/edit/preview` (`reservations.folios.lines.edit.preview`, 300 ms after a change). The tax is calculated again with the taxes in the field. Nothing is written.
- **Reason** is required (at most 500 characters), starts as the page's reason and creates its own `folio_manual_batches` row, like the other row actions. Keys: the batch is the Dialog's UUID, the new line `edit:{line_id}`. A second click writes nothing. Route: `POST …/lines/{line}/edit` (`reservations.folios.lines.edit`), `folio.manual`.
- The new line posts on the business date and keeps the original `service_date`. Closed dates never change: the reversal and the new line net to the difference on today's date.
- Refusals (all checked before the first write): `nothing_changed`, `edit_invalid` (no description or over 120 characters), `amount_invalid`, `quantity_invalid`, `tax_not_available`, `line_has_discount` (remove the discount first), `night_line` (a room night changes through Change price), `folio_not_open`, `line_is_child`, `payment_line`, the `TaxCalculator` refusals.
- The item stays so revenue never moves between categories here. To change the item, reverse the line and post a new charge.
- Edit line does not edit a payment, a refund or a child line. Remove tax, Add tax and Recalculate tax stay for single tax fixes.
- Logged on the reservation: `folio.line_edited` with the batch id, the reason, the new line and the old and new values.

## Manual marker

- A new `FolioRowTag`, **Manual** (`PencilLine` icon, neutral tone, no tint), on every top-level line with a `manual_batch_id`, on the folio tab and the Posted lines tab.
- It shows only to users with `folio.view-manual-entries`. Others see a normal line.
- Its tooltip (the same as every other row marker): "Manual entry by {user}, {date time}. Reason: {reason}." The Activity tab always has the batch (`folio.manual_batch_posted`), whatever the permission.

## Data model

```
folio_lines           + service_date (date, not null), + manual_batch_id (nullable, FK)
                      check: service_date <= posting_date
folio_manual_batches  id, property_id, folio_id, reason, user_id, idempotency_key (unique), created_at
```

- `folio_manual_batches` is never updated or deleted, like the ledger.
- The migration backfills `service_date` from `posting_date`, and for `night:` lines from the unit night's date.

## Permissions

| Permission                  | Guards                                                                             | Default roles           |
| --------------------------- | ---------------------------------------------------------------------------------- | ----------------------- |
| `folio.manual`              | The Manual Folio Editor and its row actions, plus the past and migrated stay tools | Owner, Manager          |
| `folio.view-manual-entries` | The Manual marker and its popover                                                  | Owner, Manager, Finance |

A migration syncs both to the existing roles. The rate of a live room night in **Edit line** also needs `reservations.change-price`, because it calls `ChangeNightPrice`. No new permission is added.

## Build order

| Item | Commit                                                                 | Covers                                                                                                                                               |
| ---- | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1    | `feat(folio): add the service date to folio lines`                     | The column, the CHECK, the backfill, `PostRoomCharges` and copies setting it, the folio tab, voucher and invoice showing it, the Folio ledger column |
| 2    | `feat(folio): add the manual folio page`                               | The permissions, the reason dialog, the page, `ManualFolioPreview`, `PostManualBatch`, the batch table, close after posting, the Manual marker       |
| 3    | `feat(folio): split lines and change taxes manually`                   | `SplitLine`, `RemoveLineTax`, `AddLineTax`, `RecalculateLineTax`, the row menu and Dialogs                                                           |
| 4    | `refactor(folio): rename the manual folio page to Manual Folio Editor` | The labels, the page and Dialog titles, the permission label and the docs. The key, routes, table and events stay                                    |
| 5    | `feat(folio): choose the purpose of a manual payment`                  | The Purpose select on Payment rows, `PostManualBatch` passing it                                                                                     |
| 6    | `feat(folio): show scheduled lines in the Manual Folio Editor`         | The Scheduled tab                                                                                                                                    |
| 7    | `feat(folio): add third party folios and a Move link in the editor`    | **+ Add folio** for Third party folios, the **Move charges…** link                                                                                   |
| 8    | `feat(folio): edit a charge line in one dialog`                        | `EditLine`, the Edit line Dialog, the live night rate through `ChangeNightPrice`                                                                     |

See [Past and migrated stays](../12-reservations/12-past-and-migrated-stays.md) and [Migration](../17-onboarding/07-migration.md) for the rest of the plan.
