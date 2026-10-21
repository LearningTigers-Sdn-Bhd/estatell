# Transaction codes

## Purpose

Every money line is classified by a transaction code. Codes drive revenue reports, tax rules and accounting export.

## Built first slice

Every property receives the Malaysia system-code catalogue through `SeedTransactionCodes`. The Action runs inside `SeedPropertyDefaults` for new properties. The table migration backfills existing Malaysian properties.

`system_key` is the stable application identity. `code` is the visible accounting code. Seeding again creates missing system codes but preserves every existing value. If a custom record occupies a canonical code, the system code uses the next suffix, for example `ROOM_2`.

There is no generic transaction-code editor. Charge items, taxes, payment methods and discounts own the user-facing configuration that refers to these codes. Each charge item owns one charge code: starter items own FNB, LAUNDRY, MINIBAR and FEE, and new items create their own custom code. Each discount owns one discount code the same way: the starter Discount owns DISCOUNT.

## Data model

```
transaction_codes    id, property_id, system_key (nullable), code, name,
                     kind (TransactionKind),
                     category (TransactionCategory), gl_account_code (nullable),
                     is_system, active, timestamps
                     unique (property_id, code)
                     unique (property_id, system_key) where system_key is not null
```

## Enums

- `TransactionKind`: Charge, Payment, Adjustment, Tax.
- `TransactionCategory`: Room, FoodAndBeverage, Laundry, Minibar, Fee, Transport, Tax, Discount, Cash, Card, BankTransfer, EWallet, OtaCollected, DirectBill, Deposit, Refund, Adjustment, OpeningBalance, OldSystem, Other.

## Malaysia system codes

| System key                | Code             | Name                    | Kind       | Category        |
| ------------------------- | ---------------- | ----------------------- | ---------- | --------------- |
| room_revenue              | ROOM             | Room revenue            | Charge     | Room            |
| no_show_revenue           | NO_SHOW          | No-show revenue         | Charge     | Room            |
| cancellation_revenue      | CANCEL           | Cancellation revenue    | Charge     | Room            |
| late_checkout_revenue     | LATE_CHECKOUT    | Late checkout revenue   | Charge     | Room            |
| early_departure_revenue   | EARLY_DEPARTURE  | Early departure revenue | Charge     | Room            |
| day_use_revenue           | DAY_USE          | Day use revenue         | Charge     | Room            |
| food_and_beverage_revenue | FNB              | Food and beverage       | Charge     | FoodAndBeverage |
| laundry_revenue           | LAUNDRY          | Laundry                 | Charge     | Laundry         |
| minibar_revenue           | MINIBAR          | Minibar                 | Charge     | Minibar         |
| fee_revenue               | FEE              | General fee             | Charge     | Fee             |
| damage_revenue            | DAMAGE           | Damage                  | Charge     | Other           |
| sst_tax                   | SST              | SST                     | Tax        | Tax             |
| tourism_tax               | TOURISM_TAX      | Tourism tax             | Tax        | Tax             |
| cash_payment              | CASH             | Cash                    | Payment    | Cash            |
| card_payment              | CARD             | Card                    | Payment    | Card            |
| bank_transfer_payment     | BANK_TRANSFER    | Bank transfer           | Payment    | BankTransfer    |
| ewallet_payment           | E_WALLET         | E-wallet                | Payment    | EWallet         |
| ota_collected_payment     | OTA_COLLECTED    | OTA collected           | Payment    | OtaCollected    |
| direct_bill_payment       | DIRECT_BILL      | City ledger             | Payment    | DirectBill      |
| security_deposit          | SECURITY_DEPOSIT | Security deposit        | Payment    | Deposit         |
| refund                    | REFUND           | Refund                  | Payment    | Refund          |
| old_system_payment        | OLD_SYSTEM       | Old system              | Payment    | OldSystem       |
| opening_balance           | OPENING_BAL      | Opening balance         | Adjustment | OpeningBalance  |
| adjustment                | ADJUSTMENT       | Adjustment              | Adjustment | Adjustment      |
| discount                  | DISCOUNT         | Discount                | Adjustment | Discount        |

## Rules

- System codes are seeded active with no GL account code. An active tax code does not enable the tax; the taxes catalogue controls application.
- System codes have a stable `system_key`. Custom codes do not.
- System codes have no deletion path. Owning catalogues may later rename them, assign a GL account or control whether they are available.
- Owners rarely see codes. Onboarding never shows them.
- Folio lines keep the transaction-code reference and copy their posting description and kind. Renaming a code does not rewrite those snapshots.
