# AR payments and allocation

## Purpose

Record company payments and match them to invoices.

## Data model

```
ar_payments      id, property_id, company_id, payment_method_id, amount,
                 received_on, reference, notes, received_by, timestamps
ar_allocations   id, ar_payment_id, ar_invoice_id, amount,
                 reversed_at (nullable), reversed_by (nullable), reversal_reason (nullable), timestamps
```

## Actions

- `RecordArPayment`
- `AllocateArPayment` (manual)
- `AutoAllocateArPayment` (oldest invoice first)
- `ReverseAllocation`

## Rules

- Unallocated money stays as credit on the company.
- Invoice balance = amount - active allocations.
- Company balance = open invoices - unallocated payments.

## Permissions

`city-ledger.payment.record`, `city-ledger.allocate`.

## Lessons from wastays

`ar_payment_allocation_reversals` becomes three columns on `ar_allocations`.
