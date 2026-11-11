# Payout vouchers

## Purpose

Settle each property's balance once a month: Estatell pays the property, or the property pays Estatell.

## Cycle

| Check-outs (due dates) | Voucher made | Paid on |
| ---------------------- | ------------ | ------- |
| 16 Oct – 15 Nov        | 16 Nov       | 1 Dec   |
| 16 Nov – 15 Dec        | 16 Dec       | 1 Jan   |

If the 1st falls on a Saturday or Sunday, the payout is on the Monday. There is no public holiday calendar, so admins pay a day later themselves on a holiday.

`GenerateDraftVouchers` runs at 06:00 Malaysia time on the 16th, after most night audits have closed the 15th. It takes every unvouchered entry due up to the 15th, including older ones. An entry recorded later with a due date up to the 15th goes into the next voucher. A cycle that already has its voucher is skipped, so a repeat run makes nothing.

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

A voucher with a small negative total is made as **Carried forward** at once, with no admin step (see Negative balances).

| Status            | Meaning                                     | Next                          |
| ----------------- | ------------------------------------------- | ----------------------------- |
| `draft`           | Made by the job. Admins can add adjustments | `approved`, `carried_forward` |
| `approved`        | Locked. Shown to the property               | `paid`                        |
| `paid`            | Money moved. Never changes                  | none                          |
| `carried_forward` | Total moved into the next voucher           | none                          |

`PayoutVoucherStatus::canTransitionTo()` holds these rules.

## Negative balances

A property that uses only the channel manager has no money collected, so its voucher is negative: **Owed to Estatell**.

- A voucher whose total is below zero and smaller than RM 50 is carried forward, unless the two vouchers before it were both carried forward. So a small amount waits at most two cycles; the third voucher is a Draft.
- Carrying writes two `carried_forward` entries: one inside the voucher that brings its lines to zero (`carried-out:{voucher}`), and the opposite amount due on the 16th, which the next voucher picks up (`carried:{voucher}`). The voucher's `amount` keeps the total that was carried.
- Any other negative voucher is a Draft that is approved as an amount the property owes. The admin marks it paid when the property's bank transfer arrives.

## Payment

- A platform admin pays by bank transfer to the property's first active `property_bank_accounts` row, and records the date and reference.
- A property with no active bank account cannot have a positive voucher approved. The admin page shows a warning.

## PDF

Each voucher has a printable PDF: the property, cycle, lines grouped by booking, totals, the bank account and the status. It follows the other PDF layouts and comes with the voucher page.

## Table: `payout_vouchers`

| Column                                 | Type                      | Note                                                                                                                              |
| -------------------------------------- | ------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `id`                                   | bigint                    |                                                                                                                                   |
| `property_id`                          | foreign key               |                                                                                                                                   |
| `number`                               | string(40), unique        | `PV-{YYYYMM}-{property code}` of the cycle's end, for example `PV-202611-ES1103`. No sequence: one voucher per property per cycle |
| `period_start`, `period_end`           | date                      | 16 Oct, 15 Nov                                                                                                                    |
| `due_on`                               | date                      | The payout date                                                                                                                   |
| `amount`                               | decimal(12,2)             | Signed total                                                                                                                      |
| `status`                               | string(20)                | A `PayoutVoucherStatus` value                                                                                                     |
| `snapshot`                             | jsonb, nullable           | Bank account and totals, frozen on approval                                                                                       |
| `approved_by`, `approved_at`           | foreign key, timestamp    |                                                                                                                                   |
| `paid_by`, `paid_on`, `paid_reference` | foreign key, date, string |                                                                                                                                   |
| timestamps                             |                           | Unique (`property_id`, `number`), unique (`property_id`, `period_end`)                                                            |

`snapshot` is an issued-document snapshot, one of the allowed jsonb uses. The number is Estatell's, not the hotel's, so it does not use `document_sequences`.
