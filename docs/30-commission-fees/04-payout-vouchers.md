# Payout vouchers

## Purpose

Settle each property's balance once a month: Estatell pays the property, or the property pays Estatell.

## Cycle

| Check-outs (due dates) | Voucher made | Paid on |
| ---------------------- | ------------ | ------- |
| 16 Oct – 15 Nov        | 16 Nov       | 1 Dec   |
| 16 Nov – 15 Dec        | 16 Dec       | 1 Jan   |

If the 1st is not a working day, the payout is on the next working day.

## Flow

```
16th, scheduled job
   │  one Draft voucher per property with unvouchered entries due up to the 15th
   ▼
Draft ──── admin adds adjustments, checks the lines
   │
   ▼  Approve (locks the lines, snapshots the bank account and totals)
Approved ──── the property can now see it
   │
   ▼  Mark as paid (transfer date and bank reference)
Paid
```

A voucher with a negative total under the threshold goes to **Carried forward** instead of Approved: a `carried_forward` entry moves its total into the next cycle.

| Status            | Meaning                                     | Next                          |
| ----------------- | ------------------------------------------- | ----------------------------- |
| `draft`           | Made by the job. Admins can add adjustments | `approved`, `carried_forward` |
| `approved`        | Locked. Shown to the property               | `paid`                        |
| `paid`            | Money moved. Never changes                  | none                          |
| `carried_forward` | Total moved into the next voucher           | none                          |

`PayoutVoucherStatus::canTransitionTo()` holds these rules.

## Negative balances

A property that uses only the channel manager has no money collected, so its voucher is negative: **Owed to Estatell**.

- It is carried forward while the total is under RM 50 and is not older than 3 months.
- After that it is approved as an amount the property owes. The admin marks it paid when the property's bank transfer arrives.

## Payment

- A platform admin pays by bank transfer to the property's first active `property_bank_accounts` row, and records the date and reference.
- A property with no active bank account cannot have a positive voucher approved. The admin page shows a warning.

## PDF

Each voucher has a printable PDF: the property, cycle, lines grouped by booking, totals, the bank account and the status. It follows the other PDF layouts.

## Table: `payout_vouchers`

| Column                                 | Type                      | Note                                                                   |
| -------------------------------------- | ------------------------- | ---------------------------------------------------------------------- |
| `id`                                   | bigint                    |                                                                        |
| `property_id`                          | foreign key               |                                                                        |
| `number`                               | string                    | From `document_sequences`, type `payout_voucher`                       |
| `period_start`, `period_end`           | date                      | 16 Oct, 15 Nov                                                         |
| `due_on`                               | date                      | The payout date                                                        |
| `amount`                               | decimal(12,2)             | Signed total                                                           |
| `status`                               | string(20)                | A `PayoutVoucherStatus` value                                          |
| `snapshot`                             | jsonb, nullable           | Bank account and totals, frozen on approval                            |
| `approved_by`, `approved_at`           | foreign key, timestamp    |                                                                        |
| `paid_by`, `paid_on`, `paid_reference` | foreign key, date, string |                                                                        |
| timestamps                             |                           | Unique (`property_id`, `number`), unique (`property_id`, `period_end`) |

`snapshot` is an issued-document snapshot, one of the allowed jsonb uses.
