# Payments and refunds

## Purpose

Record money received and refunded, and post it to the folio.

## Data model

```
payments    id, property_id, folio_id, payment_method_id, amount,
            purpose (PaymentPurpose), status (PaymentStatus), reference,
            refund_of_id (nullable), receipt_number, received_by, received_at,
            gateway_payload (jsonb, nullable), timestamps
```

## Enums

- `PaymentPurpose`: Advance, Settlement, SecurityDeposit.
- `PaymentStatus`: Pending, Completed, Failed.

## Actions

- `RecordPayment`: validates, creates the payment, posts a negative folio line and any surcharge line, all in one transaction. For `DirectBill` it also creates an AR invoice.
- `RefundPayment`: a payment with `refund_of_id`.

## Rules

- A deposit is a payment with a purpose. No deposit tables.
- The receipt number comes from `document_sequences`. The receipt PDF is rendered from payment + folio with dompdf.
- `gateway_payload` jsonb is added only when an online gateway is used.
- Pending payments are a hard blocker for the night audit.

## Permissions

`payment.record`, `payment.refund`.

## Lessons from wastays

`deposits`, `deposit_movements`, `receipts` (3 jsonb columns) and `payment_transactions` are replaced by one table.
