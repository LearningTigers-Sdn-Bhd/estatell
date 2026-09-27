# Payment methods

## Purpose

How guests and companies pay.

## Data model

```
payment_methods    id, property_id, transaction_code_id, name, type (PaymentMethodType),
                   surcharge_type (SurchargeType), surcharge_value,
                   surcharge_charge_item_id (nullable), requires_reference,
                   position, active, timestamps
```

## Enums

- `PaymentMethodType`: Cash, Card, BankTransfer, EWallet, OtaCollected, DirectBill.
- `SurchargeType`: None, Fixed, Percent.

## Rules

- Recording a payment with a surcharge also posts the surcharge as a charge line (for example a 2% card fee).
- `requires_reference` forces a reference, for example a card approval code.
- `DirectBill` (label "City ledger") moves the balance to the city ledger (see [AR invoices](../15-city-ledger/02-ar-invoices.md)).
- `OtaCollected` is used when an OTA collected payment (see [Booking ingestion](../21-channels/05-booking-ingestion.md)).
