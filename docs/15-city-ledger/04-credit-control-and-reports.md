# Credit control and city ledger reports

## Services

- `CreditExposure`: what a company owes or may owe: open AR plus its open folios.
- `CreditCheck`: asks whether an action keeps the company under its credit limit.
- `AgingReport`: what companies owe, by days past due.
- `StatementBuilder`: statement of account per company, shown on screen and rendered as PDF with dompdf.

## Credit exposure

`CreditExposure::forCompany(Company)` returns the exposure, the limit and the headroom. Amounts are decimal strings with two places. It reads and writes nothing else.

| Part               | Rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Open AR            | The balances of the company's invoices (amount + credit notes that are not Void - active allocations) minus its unallocated payments. A total below 0 counts as 0, because credit is not a limit                                                                                                                                                                                                                                                                              |
| Open folio balance | The balances of the company's Open folios (`payer_company_id`: Master or ThirdParty), whatever the reservation status. A departed or cancelled reservation's folio that nobody has settled yet still counts, until Settle moves it into open AR (the folio is then Closed, so it never counts twice). A balance below 0 counts as 0. A folio with no company payer is not company debt. The code keeps the historical key `inHouse`; the company page labels it "Open folios" |
| Exposure           | Open AR + open folio balance                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Limit and headroom | `companies.credit_limit`. Empty means no limit: no check and no headroom. Headroom = limit - exposure, and can be negative                                                                                                                                                                                                                                                                                                                                                    |

## Credit check

`CreditCheck::check(Company, additional)` answers allowed or refused, with the exposure, the limit and by how much the action goes over. It refuses when exposure + `additional` is above the limit. A company with no limit always passes.

| Action                                                                 | `additional`                                                                                                               | Notes                                                                                                                                                                                                                                                                                   |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SettleToCityLedger`                                                   | —                                                                                                                          | **Never refused by the limit.** Settling adds no debt: it moves an open folio balance into open AR, so the exposure is the same before and after. Blocking it would only leave the bill unsent. Over the limit, the Settle confirmation shows a warning and the invoice is still issued |
| `CreateReservation` and `UpdateReservationCompany`                     | `StayChargeTotal`: the charges still to post (nights, day use, add-ons) with discounts and tax, never below the room total | Checked when the company is set. A reservation without a company is never checked. Tax and service charge count, so RM 880 of rooms with 18% tax checks RM 1,038.40 against the limit                                                                                                   |
| Check-in ([Front desk](../18-front-desk/01-check-in-and-check-out.md)) | 0                                                                                                                          | Built with front desk, which calls `CreditCheck`                                                                                                                                                                                                                                        |

- A refusal returns `credit_limit_exceeded` and writes nothing. The limit stops **new** debt (a booking, a company change, a check-in), never the billing of debt that already exists.
- **Settle over the limit:** `FolioData.settleCreditWarning` carries "Acme Sdn Bhd is over its credit limit by MYR 100.00. The invoice is still issued." (from `CreditCheck`, additional 0) for the Settle confirmation, or null. No reason is asked and nothing is logged as an override.
- `company.credit-override` lets a user pass the check. The action takes an `overrideReason`. Without a reason the check still refuses. With one, the action goes ahead and logs `credit.overridden` on the company, with the exposure, the limit, the amount and the reason.
- The Change company Dialog and the booking form show the refusal. A user with the override permission sees the warning and a required reason field, then can continue.
- There is no payment hold. A company over its limit shows a warning badge on the companies list and the company page (**Over limit**, danger tone), and nothing else changes.

Decisions made at build time (checklist item 21, credit exposure):

- The Settle warning is a plain (default) `Alert` with an alert icon in the confirmation, because the design system has no warning tone for `Alert`.
- `FolioStatement` loads the company and runs `CreditCheck` only for the selected folio, and only when that folio offers Settle, so the folio tab query count does not grow with units.
- `ArInvoiceResult::REFUSAL_CREDIT_LIMIT_EXCEEDED` is gone: only Settle used it. The booking, company change and check-in refusals keep their own constants.

## Aging report

The Aging report and the Statement are AR only: a folio that has not been settled yet is not in them. It is in the exposure above, in the [Outstanding balance](../22-reports/01-core-reports.md) report, and in the night audit's **Master folio not billed** warning.

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
