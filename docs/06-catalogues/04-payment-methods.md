# Payment methods

## Purpose

How guests and companies pay.

## Built first slice

Every Malaysian property receives six system-backed payment methods through `SeedPaymentMethods`. Cash, Card, Bank transfer and E-wallet start active. OTA collected and City ledger start inactive. Repeated seeding restores missing methods and preserves property changes.

The settings catalogue allows staff with `settings.manage` to rename, activate, configure and reorder these methods. Methods cannot be created or deleted. Their type, transaction code and system identity do not change.

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

- Card, Bank transfer and E-wallet require a reference by default. Cash, OTA collected and City ledger do not.
- `None` has no surcharge value or charge item. `Fixed` and `Percent` require a positive value and an active charge item from the same property. Percent is at most 100.
- Deactivating a method removes it from future selection. Historical payments keep their payment-method reference.
- Reactivated methods return at the end of the active order.
- Renaming a method also renames its system transaction code. The transaction code's visible code and stable system key do not change.
- Recording a payment with a surcharge also posts the surcharge as a charge line (for example a 2% card fee).
- `requires_reference` forces a reference, for example a card approval code.
- `DirectBill` (label "City ledger") moves the balance to the city ledger (see [AR invoices](../15-city-ledger/02-ar-invoices.md)).
- `OtaCollected` is used when an OTA collected payment (see [Booking ingestion](../21-channels/05-booking-ingestion.md)).

Payment recording, surcharge posting, OTA ingestion and city-ledger invoice creation are later modules.
