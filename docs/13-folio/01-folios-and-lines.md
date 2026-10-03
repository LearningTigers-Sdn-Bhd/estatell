# Folios and ledger lines

## Purpose

The bill for a reservation, stored as an immutable ledger.

## Data model

```
folios         id, property_id, reservation_id, reservation_unit_id (nullable),
               number, type (FolioType), status (FolioStatus),
               payer_guest_id (nullable), payer_company_id (nullable),
               invoice_number (nullable), closed_at, closed_by, timestamps
               check: not both payer_guest_id and payer_company_id
folio_lines    id, folio_id, transaction_code_id, kind (TransactionKind), description,
               quantity, unit_amount, amount (signed), posting_date,
               source_type, source_id, parent_line_id (nullable),
               reversal_of_id (nullable), transfer_group_id (nullable),
               idempotency_key (unique), user_id, created_at
```

```mermaid
erDiagram
  RESERVATIONS ||--|{ FOLIOS : has
  FOLIOS ||--o{ FOLIO_LINES : ledger
  TRANSACTION_CODES ||--o{ FOLIO_LINES : classifies
  FOLIO_LINES |o--o{ FOLIO_LINES : "tax child / reversal"
  FOLIOS ||--o{ PAYMENTS : receives
```

## Enums

- `FolioType`: Master, Unit.
- `FolioStatus`: Open, Closed, Voided.

## Rules

- **Signed amounts**: charges and taxes positive, payments negative. Balance = `SUM(amount)`. Not stored.
- **Immutable**: lines are never updated or deleted. Corrections are reversal lines (`reversal_of_id`).
- **Idempotent**: `idempotency_key` is unique, for example `night:{unit_night_id}`, `payment:{id}`, `addon:{id}`.
- Tax and discount lines are children of the charge (`parent_line_id`).
- Transfers are a reversal on the source plus a new line on the target, linked by `transfer_group_id`.
- `description` and `kind` are copied at posting time.
- Postings use the current business date, never the calendar date. Closed dates reject postings.
- A unit can check out only when its folio balance is 0 or transferred to the master folio.
- A reservation closes only when all its folios are closed.
- Closing a folio assigns `invoice_number` from `document_sequences`.
- `folio_lines` has no `property_id` and no `updated_at`. The folio scopes it.
- A database trigger rejects every `UPDATE` and `DELETE` on `folio_lines`, so immutability does not depend on code discipline.
- `payer_company_id` has no foreign key until module 15 adds companies.
- CHECK constraints: not both payers; a Unit folio has a `reservation_unit_id` and a Master folio has none; `closed_at` and `closed_by` are set together; `quantity` is at least 1; `source_type` and `source_id` are set together.
- `unit_add_ons.folio_line_id` (nullable, foreign key to `folio_lines`) marks a posted add-on.
- `MergeGuests` moves the folios a merged guest pays through `RepointGuestFolios`. `AnonymizeGuest` refuses a guest who pays a folio with a balance other than 0 (voided folios are skipped).

## Services and Actions

- `FolioBalance` (service, built): the balance of one folio, and whether a guest has an open balance.
- `RepointGuestFolios` (built): called by `MergeGuests`.
- `PostCharge`, `ReverseLine`, `TransferLines` (built): see [Posting charges](02-posting-charges.md).
- `CloseFolio`, `ReopenFolio` (not built).
- `CreateFolio` (built): a unit folio is created the first time a unit receives a posting, by `PostCharge`. Nothing creates folios at booking time. See [Posting charges](02-posting-charges.md).

## Permissions

`folio.close`, `folio.reopen`. Posting permissions are in [Posting charges](02-posting-charges.md).

## Lessons from wastays

`folio_transactions` had 49 columns, 5 expression indexes on `metadata->>` keys and 5 self-referencing FKs (parent, reversal, split, moved_from, voided_by). `booking_folios` had 45 columns.
