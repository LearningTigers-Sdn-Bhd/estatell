# Payment methods

## Purpose

How guests and companies pay.

## Built first slice

Every Malaysian property receives six system-backed payment methods through `SeedPaymentMethods`. Properties in other countries receive Cash, Card and Bank transfer ([Country profiles](../02-property/03-seed-property-defaults.md#country-profiles)). Cash, Card, Bank transfer and E-wallet start active. OTA collected and City ledger start inactive. Repeated seeding restores missing methods and preserves property changes.

Staff with `settings.manage` can rename, activate, configure and reorder every method. The six seeded methods keep their type and system code.

## Custom methods

Staff can add methods, for example DuitNow QR or Touch 'n Go, so each one gets its own reports and surcharge.

- The types staff can add are Cash, Card, Bank transfer and E-wallet (`PaymentMethodType::addableCases()`). OTA collected and City ledger stay system-only.
- Adding a method also creates its own payment transaction code. The code category follows the type (`PaymentMethodType::category()`).
- Codes are upper case with `_` separators and at most 20 characters. They are unique among all transaction codes of the property. Custom codes can be edited; seeded codes cannot.
- The type is chosen when the method is added and never changes.
- `requires_reference` starts from the type default (`requiresReferenceByDefault()`), and staff can change it.
- Methods are never deleted. Historical payments keep their payment-method reference.

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
- Renaming a method also renames its transaction code. Deactivating or reactivating a method does the same to its code. A seeded code's visible code and stable system key do not change.
- Recording a payment with a surcharge also posts the surcharge as a charge line (for example a 2% card fee).
- `requires_reference` forces a reference, for example a card approval code.
- `DirectBill` (label "City ledger") moves the balance to the city ledger through **Settle to City ledger** (see [AR invoices](../15-city-ledger/02-ar-invoices.md)). It stays inactive and is not offered in Record payment: it supplies only the transaction code.
- `OtaCollected` is used when an OTA collected payment (see [Booking ingestion](../21-channels/05-booking-ingestion.md)).

Payment recording and surcharge posting are in [Payments and refunds](../14-payments/01-payments-and-refunds.md). OTA ingestion and city-ledger invoice creation are later modules.
