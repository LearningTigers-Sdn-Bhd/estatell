# Ledger

## Purpose

Record every amount Estatell holds for a property, or owes it, or is owed by it. The ledger is the source for vouchers, the admin dashboard and the property's Payouts page.

## Rules

- Entries are immutable. A correction is a reversal entry, never an edit or a delete.
- Every automatic entry has a unique idempotency key, so a repeated event writes nothing new.
- The amount is signed from the property's side: **+** is owed to the property, **−** is owed to Estatell.
- Every query is scoped by `property_id`.

## Entry types

| Type              | Sign | Written when                                   | Idempotency key                             |
| ----------------- | ---- | ---------------------------------------------- | ------------------------------------------- |
| `collected`       | +    | A Curlec payment is captured                   | `collected:{gateway_payment_id}`            |
| `refunded`        | −    | A Curlec refund succeeds                       | `refunded:{gateway_refund_id}`              |
| `refund_fee`      | −    | A Curlec refund succeeds (kept fee + RM 0.50)  | `refund-fee:{gateway_refund_id}`            |
| `commission`      | −    | The booking ends                               | `commission:{service}:{reservation_id}:{n}` |
| `adjustment`      | ±    | A platform admin adds one, with a reason       | none (manual)                               |
| `carried_forward` | ±    | A voucher is carried forward into the next one | `carried:{payout_voucher_id}`               |

`collected` and `refunded` take the due date of their reservation. Until the reservation ends, they count in Total Cash Liability but not in a voucher.

## Writing entries

- `PostCommissionEntry` is the only writer. A repeat with the same key returns the existing entry and writes nothing.
- `ReverseCommissionEntry` writes the opposite entry: the amount and base are negated, the rate and links are kept, `reverses_entry_id` points to the original, and the key is `reversal:{id}`. An entry is reversed at most once, and a reversal is never reversed.
- CHECK constraints keep the rules in the database: a non-zero amount; service, base and rate exactly on `commission` entries; a reservation on collected, refunded, refund fee and commission entries; a payment on `collected`; a refund on `refunded` and `refund_fee`; a reason on adjustments.

## Refund cost

On a full refund, the property pays the fee Curlec kept and the RM 0.50 refund fee. On a partial refund, the property pays the RM 0.50 only, because Estatell pays Curlec's normal fee from its commission. See [Cancel and refund](../29-booking-engine/05-cancel-and-refund.md#cost).

## Tables

### `commission_rates`

| Column           | Type         | Note                                 |
| ---------------- | ------------ | ------------------------------------ |
| `id`             | bigint       |                                      |
| `service`        | string(30)   | A `CommissionService` value          |
| `rate`           | decimal(5,2) | For example 10.00                    |
| `effective_from` | date         | Bookings made on or after this date  |
| `created_by`     | foreign key  | `users`                              |
| timestamps       |              | Unique (`service`, `effective_from`) |

The migration seeds booking engine 10.00 and channel manager 3.00, both from 2026-01-01, so every booking has a rate. The channel manager start date is `platform_settings.channel_manager_commission_from`, empty by default: no channel manager commission until an admin sets it.

### `commission_entries`

| Column               | Type                    | Note                                                               |
| -------------------- | ----------------------- | ------------------------------------------------------------------ |
| `id`                 | bigint                  |                                                                    |
| `property_id`        | foreign key             |                                                                    |
| `reservation_id`     | foreign key, nullable   | Null for adjustments and carried-forward entries                   |
| `type`               | string(20)              | A `CommissionEntryType` value                                      |
| `service`            | string(30), nullable    | Set on `commission` entries                                        |
| `base_amount`        | decimal(12,2), nullable | The amount before tax that commission used                         |
| `rate`               | decimal(5,2), nullable  | The rate used                                                      |
| `amount`             | decimal(12,2)           | Signed                                                             |
| `due_date`           | date, nullable          | Null until the reservation ends                                    |
| `gateway_payment_id` | foreign key, nullable   | For `collected`                                                    |
| `gateway_refund_id`  | foreign key, nullable   | For `refunded` and `refund_fee`                                    |
| `payout_voucher_id`  | foreign key, nullable   | Set when the entry goes into a voucher. Added with payout vouchers |
| `reverses_entry_id`  | foreign key, nullable   | The entry this one reverses                                        |
| `idempotency_key`    | string, nullable        | Unique (`property_id`, `idempotency_key`)                          |
| `reason`             | string, nullable        | Required for adjustments                                           |
| `created_by`         | foreign key, nullable   | Null for automatic entries                                         |
| `created_at`         | timestamp               | No `updated_at`                                                    |

Indexes: (`property_id`, `due_date`), (`property_id`, `payout_voucher_id`), `reservation_id`.

## Totals

| Total                | Meaning                                                                                        |
| -------------------- | ---------------------------------------------------------------------------------------------- |
| Total Cash Liability | Sum of `collected` and `refunded` entries for all properties that are not in a paid voucher    |
| Immediate Payable    | Sum of the next voucher run: entries with a due date up to the last cut-off, not yet vouchered |
| Owed to Estatell     | Sum of negative property balances                                                              |
| Estatell income      | Sum of `commission` entries (shown as a positive number)                                       |
