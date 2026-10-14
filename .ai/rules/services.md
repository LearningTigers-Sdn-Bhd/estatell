---
paths:
  - 'app/Services/**'
---

# Services

## Services are stateless reads and calculations
Services only read and calculate (`AvailabilityService`, `TaxCalculator`, `FolioBalance`); they never write. Mode differences use strategy classes chosen by an enum (e.g. `PricingBasis::strategy()`), not `if`/`match` branches spread through code. Keep each class focused; split before it grows past one responsibility. Money math uses `brick/money` for amounts with a currency, and `Brick\Math\BigDecimal` for sums and comparisons of plain decimal strings (ledger balances). Never use bcmath or floats for money.
