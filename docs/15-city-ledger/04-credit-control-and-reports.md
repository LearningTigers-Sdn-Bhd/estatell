# Credit control and city ledger reports

## Services

- `CreditExposure`: what a company owes or may owe: open AR plus its in-house folios.
- `CreditCheck`: asks whether an action keeps the company under its credit limit.
- `AgingReport`: what companies owe, by days past due.
- `StatementBuilder`: statement of account per company, shown on screen and rendered as PDF with dompdf.

## Credit exposure

`CreditExposure::forCompany(Company)` returns the exposure, the limit and the headroom. Amounts are decimal strings with two places. It reads and writes nothing else.

| Part               | Rule                                                                                                                                                                                             |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Open AR            | The balances of the company's invoices (amount + credit notes that are not Void - active allocations) minus its unallocated payments. A total below 0 counts as 0, because credit is not a limit |
| In-house balance   | The balances of the company's Open folios (`payer_company_id`) on reservations that are not Cancelled or Completed. A balance below 0 counts as 0                                                |
| Exposure           | Open AR + in-house balance                                                                                                                                                                       |
| Limit and headroom | `companies.credit_limit`. Empty means no limit: no check and no headroom. Headroom = limit - exposure, and can be negative                                                                       |

## Credit check

`CreditCheck::check(Company, additional)` answers allowed or refused, with the exposure, the limit and by how much the action goes over. It refuses when exposure + `additional` is above the limit. A company with no limit always passes.

| Action                                                                 | `additional`                              | Notes                                                                                                    |
| ---------------------------------------------------------------------- | ----------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `SettleToCityLedger`                                                   | 0                                         | The balance is already in the in-house figure, so settling only refuses a company already over its limit |
| `CreateReservation` and `UpdateReservationCompany`                     | The stay total of the booking's new units | Checked when the company is set. A reservation without a company is never checked                        |
| Check-in ([Front desk](../18-front-desk/01-check-in-and-check-out.md)) | 0                                         | Built with front desk, which calls `CreditCheck`                                                         |

- A refusal returns `credit_limit_exceeded` and writes nothing.
- `company.credit-override` lets a user pass the check. The action takes an `overrideReason`. Without a reason the check still refuses. With one, the action goes ahead and logs `credit.overridden` on the company, with the exposure, the limit, the amount and the reason.
- The Settle button, the Change company Dialog and the booking form show the refusal. A user with the override permission sees the warning and a required reason field, then can continue.
- There is no payment hold. A company over its limit shows a warning badge on the companies list and the company page (**Over limit**, danger tone), and nothing else changes.

## Aging report

`AgingReport::build(Property, asAt)` returns one row per company with a balance, and totals.

| Column  | Rule                                                                                     |
| ------- | ---------------------------------------------------------------------------------------- |
| Current | Open invoices not yet due (`due_on` on or after the date)                                |
| 1-30    | Days past due 1 to 30                                                                    |
| 31-60   | Days past due 31 to 60                                                                   |
| 61-90   | Days past due 61 to 90                                                                   |
| Over 90 | Days past due above 90                                                                   |
| Credit  | The company's unallocated payments, shown as a negative amount                           |
| Total   | The sum of the buckets plus the credit (the company's AR balance, which can be negative) |

- Only Open invoices with a balance above 0 count, each by its own balance, at the business date (or the date given).
- A company with nothing owed and no credit is left out.
- Page: **City ledger › Aging** (`GET /p/{code}/finance/city-ledger/aging`, `finance.city-ledger.aging`, `company.view`). An **As at** date (default: the business date), the table with a totals row, a company link on each row, and **Download CSV** (`.../aging.csv`) and **Download PDF** (`.../aging.pdf`).
- The sidebar shows **City ledger › Aging** after Invoices.

## Statement of account

`StatementBuilder::build(Company, from, to)` returns the statement of one company.

- Opening balance: the company's AR balance at the end of the day before `from`, from the documents issued and payments received before it.
- Lines in date order (then number): invoices (+), credit notes (-) and payments (-). A Void document and a Voided payment are left out. A payment shows once at its full amount, not by allocation.
- Closing balance: opening balance + the lines. It equals the company's AR balance on `to` when the date range reaches today.
- The aging buckets as at `to`, for the invoices that were open then (by current allocations, so a past date is an estimate: the statement says "as at today's allocations").
- Page: the company's **Statement** tab (`GET .../companies/{company}/statement`, `finance.city-ledger.companies.statement`, `company.view`). A **From** and **To** date (default: the first of the month to the business date), the statement on screen, and **Download PDF** (`.../statement.pdf`) with the shared letterhead, the company's billing details, the lines and the closing balance.

## Permissions

| Permission                | Allows                                   | Default roles  |
| ------------------------- | ---------------------------------------- | -------------- |
| `company.credit-override` | Pass a credit limit check, with a reason | Owner, Manager |

The aging page, the statement and the PDFs need `company.view`.

## Build order

1. `CreditExposure`, `CreditCheck`, the permission, and the checks in `SettleToCityLedger`, `CreateReservation` and `UpdateReservationCompany`.
2. `AgingReport` and `StatementBuilder`.
3. The Statement tab, the Aging page, the PDFs and CSV, and the over-limit badges.

## Out of scope

Payment submissions by companies, payment intents, company payment holds, MyInvois e-invoicing.

The company portal is planned as the last module: see [Company portal](../23-company-portal/01-company-portal.md).
