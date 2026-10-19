# Manual folio

## Purpose

Let a manager settle a folio by hand: add charges and payments that were missed, with the day they really happened, and fix lines, in one page with one reason.

Hotels ask for this when a stay was run on paper, entered late, or moved from another PMS. wastays did it with a **Posting date** field and an `override_night_audit` switch that wrote lines into closed business dates. estatell never writes into a closed date. It keeps two dates on each line instead.

## Two dates on a line

| Column               | Meaning                                                                 | Rule                                                                                        |
| -------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `posting_date`       | The business date the line counts in: revenue, tax, cash-up, statistics | Always the current business date, as today. Closed dates never change.                      |
| `service_date` (new) | The day it really happened                                              | Not null. Equal to `posting_date` unless a manual line, a night or a migrated line sets it. |

- `service_date` is never after `posting_date`. A database CHECK enforces it.
- The folio tab, the manual folio page, the voucher and the folio invoice show and sort by `service_date`. A row whose `service_date` is before its `posting_date` adds a muted second line, "posted {posting date}".
- Reports keep counting by `posting_date`. The Folio ledger report gains a hidden-by-default **Service date** column. The Payments report's cash-up shows the service date next to the posting date, so a cashier can match the drawer.
- `PostRoomCharges` sets `service_date` to the night. A Retry or a catch-up night then sorts on its own night, and the description no longer needs "night of {date}" (it keeps it for the ledger report).
- Child lines (tax, discount) and reversals copy the `service_date` of their parent or original line. A transfer copy keeps it too.

## Entry

On the folio tab, the **More actions** dropdown gains a **Manual** group with **Manage manually…**. It shows only on an Open folio (a Closed folio is reopened first, with the existing rules) and only to users with `folio.manual`.

```
┌─ Manage folio manually ─────────────────────────────────┐
│  Lines you add post today (10 Oct) and keep the date    │
│  you enter as their service date.                       │
│                                                         │
│  Reason *                                               │
│  ┌───────────────────────────────────────────────────┐  │
│  │ e.g. Entering charges from the paper folio        │  │
│  └───────────────────────────────────────────────────┘  │
│                               [Cancel]  [Continue]      │
└─────────────────────────────────────────────────────────┘
```

**Continue** opens the manual folio page. The reason is kept in the page's form state and saved with the first post. Nothing is written until the first post.

## Page

A full page in the Form archetype, with the same shell as the [booking form](../12-reservations/05-booking-form.md#container-and-layout): the `fixed` layout variant, two columns from `xl` (form on the left, a sticky summary rail on the right), and `FormActions` always visible at the bottom. Below `xl` the rail moves under the form. At phone width the rows stack into cards.

```
┌─ Folio GRH-26300118 · Room 103 · Ahmad Faiz › Manage manually ───────────────────────────────┐
│ ┌──────────────────────────────────────────────────────────────────────────┬────────────────┐ │
│ │ Manage folio manually                                                    │ SUMMARY        │ │
│ │ Reason: Entering charges from the paper folio  [Edit]                    │       Now After│ │
│ │ Lines post today (10 Oct) and keep the date you enter.                   │ Charges 540 580│ │
│ │                                                                          │ Payments  0-580│ │
│ │ New lines (4)   Posted lines (14)                                        │ Balance 540   0│ │
│ │ ─────────────                                                            │                │ │
│ │ Type    │Date   │Item / method│Description  │Qty│Amount│Taxes       │Total  │Folio│ │ By folio       │ │
│ │ Charge ▾│08 Oct▾│Minibar ▾    │e.g. 2 x Coke│ 2 │  6.00│SST 8% ×  ▾ │12.96 ⓘ│This▾│🗑│ │  Room 103  0.00│ │
│ │ Charge ▾│09 Oct▾│Laundry ▾    │e.g. Shirts  │ 1 │ 25.00│No taxes ✎▾ │25.00 ⓘ│This▾│🗑│ │                │ │
│ │ Payment▾│08 Oct▾│Cash ▾       │e.g. Deposit │ · │200.00│·           │-200.00│This▾│🗑│ │ [✓] Close the  │ │
│ │ Payment▾│10 Oct▾│Card · Visa ▾│e.g. Final   │ · │342.96│·           │-342.96│This▾│🗑│ │ folio after    │ │
│ │                                                              [+ Add line]│ posting        │ │
│ └──────────────────────────────────────────────────────────────────────────┴────────────────┘ │
├───────────────────────────────────────────────────────────────────────────────────────────────┤
│                                                               [Discard]  [Post 4 lines]       │
└───────────────────────────────────────────────────────────────────────────────────────────────┘
```

| Part                | Rule                                                                                                                                                                                                                                                                      |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Header              | `PageHeader` with a breadcrumb back to the folio tab. The reason sits under the title. **Edit** reopens the reason dialog.                                                                                                                                                |
| Tabs                | `Tabs`, `line` variant, an icon in each. **New lines** opens first. **Posted lines** is `FolioRowsTable`, read only, with the row menu below. These are client tabs inside one form, like the booking form's tabs, not page tabs.                                         |
| New lines           | The add-and-remove rows table ([Form containers](../design-system/05-form-containers.md)): **+ Add line** in `TableFooter` on the right, a trash icon to remove. One row per line.                                                                                        |
| Type                | Charge, Payment or Refund. Payment and Refund rows show Method and Reference in place of Item, Qty and Taxes.                                                                                                                                                             |
| Date                | The service date. From the reservation's creation date (a deposit before arrival) to the business date. Never before the property's first business date, except with the **Old system** method ([Migration](../17-onboarding/07-migration.md#old-system-payment-method)). |
| Item                | A charge item, as in the Post charge Dialog. The amount follows `default_amount` and `allow_override`.                                                                                                                                                                    |
| Taxes               | A `MultiCombobox`, the same field as on the charge item page (`taxLabel()`). It starts with the charge item's own taxes, and picking another item resets it. A choice that differs from the item shows ✎ with the tooltip "Taxes changed from the item's default".        |
| Total               | Includes tax. Its ⓘ popover lists each tax and its amount, like `BookingQuoteCell` on the booking form. A foreign-only tax for a local guest shows "Exempt" and 0.00: the nationality rule still applies.                                                                 |
| Folio               | "This" or another Open folio of the reservation. A Unit folio must be the unit's own.                                                                                                                                                                                     |
| Summary rail        | Charges, payments and balance, **Now** and **After**, plus a balance for each folio the rows touch. Every figure comes from the server preview.                                                                                                                           |
| Close after posting | A checkbox in the rail. Ticked by default when the balance after posting is 0.00. Disabled with the `CloseFolio` reason as a hint when the folio cannot close (`units_in_house`, `folio_receives_scheduled_charges`).                                                     |
| Footer              | **Post N lines**. **Discard** asks to confirm when there are draft rows. Leaving the page with drafts asks too (`useFormOptions`).                                                                                                                                        |
| Errors              | Inline on the row through `FormField`, focus on the first refused row. Nothing posts.                                                                                                                                                                                     |

## Preview and post

- `ManualFolioPreview` (`app/Services/Folio/`) takes the draft rows and returns each row's tax lines, total and refusal, and the rail figures. The page calls it through a `useHttp` request when a row changes, for that row only (the same pattern as the booking form quote). It writes nothing.
- `PostManualBatch` (`app/Actions/Folio/`) takes the actor, the folio, the reason, the rows and the close flag. In one transaction, with every touched folio locked in id order:
    1. Creates the `folio_manual_batches` row.
    2. Posts each row in order through the existing Actions: a charge through `PostCharge` (with `folio_id`, `service_date`, `tax_ids` and `manual_batch_id`), a payment through `RecordPayment`, a refund through `RefundPayment`.
    3. Runs `CloseFolio` when the flag is on.
- Any refusal rolls back everything, including the batch row and receipt numbers, and names the row.
- `PostCharge` and `RecordPayment` take two new optional inputs: `service_date` (checked against the range above, refusal `service_date_invalid`) and `manual_batch_id`. `PostCharge` also takes an optional `tax_ids` list, used only from this page: `TaxCalculator` runs for the chosen taxes instead of the item's own.
- The form's UUID is the batch key. A repeated submit returns the existing batch and writes nothing.
- Logged on the reservation: `folio.manual_batch_posted` with the batch id, the reason and the number of lines.

## Row menu on posted lines

On the **Posted lines** tab, each top-level row's ⋯ menu adds manual actions to the existing ones:

```
│  09 Oct   Laundry · Shirts      1     25.00   ⋯ ┐
│                                  ┌──────────────┴───┐
│                                  │ Reverse          │
│                                  │ Move to folio…   │
│                                  │ Split…           │
│                                  │ ──────────────── │
│                                  │ Remove tax…      │
│                                  │ Add tax…         │
│                                  │ Recalculate tax  │
│                                  └──────────────────┘
```

Each one posts at once in its own Dialog (they are not draft rows). The Dialog's reason field starts with the batch reason and can be edited. Every action creates its own `folio_manual_batches` row, so each line still points to a reason. After an action, both tabs and the rail reload.

| Action          | Dialog                                                                               | Writes                                                                                                                                                               | Refusals                                                                          |
| --------------- | ------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| Split           | Two or more rows of folio and amount, **+ Add folio**, the total must equal the line | `SplitLine`: a reversal of the line and its children, then one charge per row on its folio, with the same item, service date and tax choice, one `transfer_group_id` | `split_total_mismatch`, `folio_not_open`, `line_not_transferable`, `payment_line` |
| Remove tax      | `MultiCombobox` of the taxes posted on the charge, a reason                          | `RemoveLineTax`: a reversal of each chosen tax child line only. The charge stays.                                                                                    | `tax_not_on_line`, `already_reversed`, `folio_not_open`                           |
| Add tax         | `MultiCombobox` of the enabled taxes not on the charge, a reason                     | `AddLineTax`: a new tax child under the charge, calculated by `TaxCalculator` for the chosen taxes                                                                   | `tax_already_on_line`, the `TaxCalculator` refusals, `folio_not_open`             |
| Recalculate tax | A confirmation with the before and after tax, a reason                               | `RecalculateLineTax`: reverses the current tax children and posts fresh ones with the item's own taxes and today's nationality                                       | `tax_unchanged`, the `TaxCalculator` refusals, `folio_not_open`                   |

- **The `line_is_child` rule changes.** `ReverseLine` still refuses a child line on its own. `RemoveLineTax` and `RecalculateLineTax` are the only paths that reverse a **tax** child without its parent. A discount child still reverses only with its parent.
- Keys: `split:{line_id}:{n}`, `tax-remove:{tax_line_id}`, `tax-add:{line_id}:{tax_id}:{n}`, `tax-recalc:{line_id}:{n}`, where n counts the earlier ones on that line.
- **A room night's tax.** Remove tax fixes only that night. The audit charges the next nights with the normal taxes. A real exemption for a whole stay is a later item (see the [checklist](../checklist.md#later)).
- The tax report counts these on their posting date, like any reversal.

## Manual marker

- A new `FolioRowTag`, **Manual** (`PencilLine` icon, neutral tone, no tint), on every top-level line with a `manual_batch_id`, on the folio tab and the Posted lines tab.
- It shows only to users with `folio.view-manual-entries`. Others see a normal line.
- Its popover: "Manual entry by {user}, {date time}. Reason: {reason}." The Activity tab always has the batch, whatever the permission.

## Data model

```
folio_lines           + service_date (date, not null), + manual_batch_id (nullable, FK)
                      check: service_date <= posting_date
folio_manual_batches  id, property_id, folio_id, reason, user_id, idempotency_key (unique), created_at
```

- `folio_manual_batches` is never updated or deleted, like the ledger.
- The migration backfills `service_date` from `posting_date`, and for `night:` lines from the unit night's date.

## Permissions

| Permission                  | Guards                                                                           | Default roles           |
| --------------------------- | -------------------------------------------------------------------------------- | ----------------------- |
| `folio.manual`              | The manual folio page and its row actions, plus the past and migrated stay tools | Owner, Manager          |
| `folio.view-manual-entries` | The Manual marker and its popover                                                | Owner, Manager, Finance |

A migration syncs both to the existing roles.

## Build order

| Item | Commit                                               | Covers                                                                                                                                               |
| ---- | ---------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1    | `feat(folio): add the service date to folio lines`   | The column, the CHECK, the backfill, `PostRoomCharges` and copies setting it, the folio tab, voucher and invoice showing it, the Folio ledger column |
| 2    | `feat(folio): add the manual folio page`             | The permissions, the reason dialog, the page, `ManualFolioPreview`, `PostManualBatch`, the batch table, close after posting, the Manual marker       |
| 3    | `feat(folio): split lines and change taxes manually` | `SplitLine`, `RemoveLineTax`, `AddLineTax`, `RecalculateLineTax`, the row menu and Dialogs                                                           |

See [Past and migrated stays](../12-reservations/12-past-and-migrated-stays.md) and [Migration](../17-onboarding/07-migration.md) for the rest of the plan.
