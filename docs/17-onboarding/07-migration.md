# Migration from another PMS

## Purpose

Help a property that moves from another PMS carry over what is still open on go-live day: guests in the house, deposits already paid, and money companies still owe. Nothing else is copied.

## The rule

**Before go-live belongs to the old PMS. From go-live it belongs to estatell.**

- The detail of the past (old nights, old invoices, old reports) stays in the old PMS.
- estatell carries only what is still open, through four bridges. None of them counts in estatell's revenue, statistics or cash-up.

| Bridge              | Where                                                                                       | What it carries                                  |
| ------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| Opening balance     | A [migrated stay](../12-reservations/12-past-and-migrated-stays.md#one-amount) folio        | What an in-house guest still owes, as one amount |
| Copied old folio    | A [migrated stay](../12-reservations/12-past-and-migrated-stays.md#copy-line-by-line) folio | The same, line by line, for a 1:1 folio          |
| Old system payment  | Any reservation's folio                                                                     | A deposit or advance paid in the old PMS         |
| Add from old system | A [company](../15-city-ledger/05-old-system-balances.md)                                    | Unpaid company invoices and credit on account    |

**Go-live date** means the property's first business date. An admin can still set it earlier for an owner who wants to replay past stays day by day and run the audit for each day ([Go live](03-go-live.md#actions)). That replay needs none of the bridges.

## Migration window

`property_settings.migration_window_days` (integer, default 60, 0 to 365). Staff with `settings.manage` edit it on **Settings › Details › Operations**, with the hint "How long after go-live you can bring in stays, deposits and balances from your old system."

The window is open while the business date is before the go-live date plus the window. `MigrationWindow` (`app/Services/Property/`) answers `isOpen(Property)` and `closesOn(Property)`.

| Inside the window only                                             | Refusal after the window  |
| ------------------------------------------------------------------ | ------------------------- |
| Migrated stay mode on the booking form                             | `migration_window_closed` |
| Copy line by line, Edit old folio                                  | `migration_window_closed` |
| Change opening balance                                             | `migration_window_closed` |
| The **Old system** payment method on folios and in the city ledger | `method_not_available`    |
| **Add from old system** on a company                               | `migration_window_closed` |

After the window the bridges disappear from the UI. Lines already posted stay, and are corrected like any line: a reversal or a refund.

## Opening balance code

A system transaction code seeded with the others by `SeedTransactionCodes`, so every property has it:

| System key        | Code        | Name            | Kind       | Category       |
| ----------------- | ----------- | --------------- | ---------- | -------------- |
| `opening_balance` | OPENING_BAL | Opening balance | Adjustment | OpeningBalance |

- `TransactionCategory` gains **OpeningBalance**. It is not revenue, not tax and not a payment.
- Only `PostOpeningBalance` and `ChangeOpeningBalance` post it. No charge item uses it, so it never shows in Post charge.

## Old system payment method

A system payment method seeded by `SeedPaymentMethods`, **inactive**. Nothing turns it on: it is offered by the migration window, not by the `active` flag.

| Item       | Rule                                                                                                                                                                                                                                                                                                                   |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Type       | `PaymentMethodType::OldSystem` (new, system-only like OTA collected and City ledger). Its code: system key `old_system_payment`, code OLD_SYSTEM, kind Payment, category **OldSystem** (new).                                                                                                                          |
| Who        | `folio.manual`, or a platform admin running a console import (`wastays:import-stays`), inside the window. It shows in Record payment, the Manual Folio Editor and the old folio page, and in **Add from old system** on a company.                                                                                     |
| Date       | The day the old PMS took the money: `service_date` on a folio payment, `received_on` on a city ledger payment. **The only payment that may be dated before the go-live date.** Any real date, with no earliest day, and never after the business date. It skips `ServiceDateRange`, which other methods keep.          |
| Reference  | The old receipt number. Required.                                                                                                                                                                                                                                                                                      |
| Purpose    | Advance or Security deposit on a folio, with the existing placement rules (`advance_on_master`, `deposit_on_unit_only`).                                                                                                                                                                                               |
| Surcharge  | None.                                                                                                                                                                                                                                                                                                                  |
| Receipt    | No receipt number: estatell did not receive the money. `payments_receipt_check` becomes "a refund has no receipt number" (`refund_of_id IS NULL OR receipt_number IS NULL`), and `RecordPayment` requires a receipt number for every other method. The folio and the invoice print "Paid in old system · {reference}". |
| Correction | A refund. A refund to **Old system** moves no money and is the fix for a wrong entry. Money really given back uses a real method.                                                                                                                                                                                      |

Settings › Payment methods does not list Old system, like OTA collected: only the window offers it.

Rule for staff, shown as a hint in the payment Dialog and on the company dialog: **an advance tied to a booking goes on its folio; money not tied to a booking goes on the company.**

## Reports

| Report                                    | Treatment                                                                                                                                                                                                  |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Revenue, statistics, Occupancy, dashboard | Leave out migrated lines (`folio_lines.migrated`), the Opening balance code and Old system payments. Day-by-day figures before go-live belong to the old PMS.                                              |
| Tax report                                | Normal tax lines as today. Tax on migrated lines is a separate row group, **Migrated from old system**, under the normal rows and outside the main total, so the accountant decides whether to include it. |
| Payments and cash-up                      | Leave out the Old system method, in the rows, the totals and the By staff cash-up.                                                                                                                         |
| Folio ledger                              | Shows every line, with the Migrated marker and the new **Service date** column.                                                                                                                            |
| Outstanding balance, Deposit liability    | Include everything: these are balances, and the old balance is really owed or held.                                                                                                                        |
| City ledger aging, statement              | Include migrated invoices with their real dates. The statement shows "Old system · {number}".                                                                                                              |

Example: the old PMS charged 1–9 Oct with SST 155.52 and its daily reports show it. If estatell counted the copied lines too, October SST would show 311.04. The separate row keeps 155.52 visible without adding it twice.

```
│ SST 8%                         Lines     Tax    │
│ Posted since go-live              48   992.00   │
│ ─────────────────────────────────────────────── │
│ Migrated from old system           9   155.52   │
│ Already declared in the old system? Leave out.  │
```

## What is not migrated

| Item                                     | Instead                                                                                                                                                                             |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stays that ended before go-live          | Entered as an [Old stay](../12-reservations/12-past-and-migrated-stays.md#old-stay), history only. Guest profiles come in through the guest list. Old invoices stay in the old PMS. |
| Old line items when one amount is enough | One opening balance.                                                                                                                                                                |
| Future bookings                          | Entered as normal bookings. A deposit paid in the old PMS uses the Old system method.                                                                                               |
| Old invoices to reprint                  | Printed from the old PMS. estatell has no folio for them.                                                                                                                           |
| E-invoice submission of old invoices     | Never: the old system submitted them.                                                                                                                                               |

## Go-live page

The go-live review shows a note when the window will open: "For 60 days after go-live you can bring in guests in the house, deposits and company balances from your old system." The **You're live** card links to the help for each bridge.

## Build order

| Item | Commit                                                      | Covers                                                                                                                                                                                                                          |
| ---- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1    | `feat(onboarding): add the migration window`                | `migration_window_days` on the Operations tab, `MigrationWindow`, the `opening_balance` code and the OpeningBalance category, the Old system method, type, code and category, seeding for existing properties, the go-live note |
| 2    | `feat(payments): record payments from the old system`       | Record payment and the Manual Folio Editor offering Old system inside the window, the date and reference rules, no receipt number, the print line, reports leaving it out. Needs: the migration window, the Manual Folio Editor |
| 3    | `feat(reports): keep migrated lines out of revenue and tax` | The `migrated` filter in `DailyRevenueQuery`, statistics and the dashboard, the Migrated from old system rows on the tax report. Needs: copy old folios line by line                                                            |
