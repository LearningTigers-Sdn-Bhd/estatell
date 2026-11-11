# LHDN MyInvois E-Invoicing

## Purpose

Automate statutory e-invoicing compliance with the Inland Revenue Board of Malaysia (LHDN / Lembaga Hasil Dalam Negeri Malaysia) via the MyInvois Open API gateway. It supports live submission of closed reservation folios, City Ledger accounts receivable (AR) invoices, monthly B2C consolidations, and credit notes.

## Scope

| Now                                                                        | Later                                                  |
| -------------------------------------------------------------------------- | ------------------------------------------------------ |
| Submission of closed reservation folios (`01` Invoice)                     | Self-billed e-invoices (`11`) for OTA commissions      |
| Submission of City Ledger AR invoices (`01` Invoice)                       | Debit Notes (`03`) and Refund Notes (`04`)             |
| Credit Notes (`02`) referencing original validated submission UUID         | Webhook receiver for real-time buyer rejections        |
| Monthly B2C consolidation batching with General Public TIN `EI00000000010` | Automated XML/UBL export download button               |
| 72-hour cancellation window enforcement on LHDN                            |                                                        |
| Unsigned v1.0 documents (SHA-256 document hash)                            | Digital signing (v1.1), once `DocumentSigner` is fixed |
| Mock, Sandbox, and Production gateway environments                         |                                                        |
| Behind the `EInvoicing` Pennant feature flag, off by default               |                                                        |

## Feature gate

- Pennant feature `EInvoicing` (`einvoicing`), toggled per property by superadmins on the property features screen (`/admin/properties/{property}`).
- When inactive, every e-invoice route answers 404 through the `einvoicing` middleware (`EnsureEInvoicingIsActive`): settings, the Finance e-invoice pages, and the folio and AR invoice submit and cancel actions. Sidebar items in Settings and Finance are hidden, and the folio tab hides **Issue e-invoice**.
- `SubmitFolioEInvoice` also refuses while the flag is off, so auto-submit on checkout does nothing. `MonthlyConsolidationJob` skips the property.
- Turning the flag off keeps the property's e-invoice settings, so turning it back on needs no setup.

## LHDN Mandate & Implementation Phasing

LHDN mandates electronic invoicing under Section 82C of the Income Tax Act 1967. Implementation phases:

| Phase     | Effective Date | Annual Revenue Threshold                        | Details                                                                                                                                                                                                                                         |
| --------- | -------------- | ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 1   | 1 August 2024  | Annual turnover > RM 100 million                | Active enforcement                                                                                                                                                                                                                              |
| Phase 2   | 1 January 2025 | Annual turnover RM 25 million to RM 100 million | Active enforcement                                                                                                                                                                                                                              |
| Phase 3   | 1 July 2025    | Annual turnover RM 5 million to RM 25 million   | Mandated                                                                                                                                                                                                                                        |
| Phase 4   | 1 January 2026 | All remaining taxpayers (up to RM 5 million)    | Extended transition/grace period until **31 December 2027**; full legal penalty enforcement begins **1 January 2028**. Monthly consolidation allowed during grace period, but single stays > RM 10,000 strictly require individual e-invoicing. |
| Exemption | Permanent      | Annual revenue below **RM 3,000,000**           | Formally exempted from mandatory issuance (Budget 2025). Non-issuance permitted unless explicitly requested by the buyer.                                                                                                                       |

## Document Types & Classification Codes

| Code | Type        | Estatell Usage                                                               |
| ---- | ----------- | ---------------------------------------------------------------------------- |
| `01` | Invoice     | Standard closed folio, City Ledger AR invoice, or monthly B2C consolidation  |
| `02` | Credit Note | Ledger corrections, post-checkout refunds, and cancellations beyond 72 hours |
| `03` | Debit Note  | Additional debit charges to a previously validated invoice                   |
| `04` | Refund Note | Return of overpayments to buyer                                              |

### LHDN Classification Codes

- `022`: Accommodation & Hospitality Services (default for hotel room stays).
- `004`: Consolidated e-invoice for general public consumer transactions.
- MSIC code `55101`: Hotels and resort hotels (configurable per property).

## Digital Signing (v1.0 vs v1.1)

LHDN accepts two document versions:

1. **Version 1.0 (unsigned)**: the document carries a SHA-256 hash. This is what estatell sends today.
2. **Version 1.1 (signed)**: adds an XAdES signature made with an X.509 certificate and RSA key.

Signing is hidden, the same as in WAStays. `DocumentSigner` does not yet build the signature LHDN expects (no `SignedProperties`, and the digests are computed over the wrong bytes), so LHDN would reject a signed document. The settings page does not show the signing fields, and the settings controller does not accept them. The columns stay on `property_e_invoice_settings` so signing can come back after `DocumentSigner` is rewritten and verified on LHDN preprod.

## Document rules

- **Issue date and time are UTC.** `IssueDate` and `IssueTime` use the UTC clock (`20:30:00Z`). LHDN reads them as UTC, so Malaysian time would put the document 8 hours in the future.
- **Country codes are ISO 3166-1 alpha-3.** Guests and companies store alpha-2 (`SG`). `CountryOptions::alpha3()` converts them (`SGP`) from the PHP intl data. An empty country is sent as `MYS`.
- **Status refresh.** LHDN validates in the background. After a submission, if LHDN still says "Submitted", `RefreshEInvoiceStatusJob` is queued (after the transaction commits). It checks again after 30 s, 1, 2 and 5 minutes. After that, staff can press Refresh on the submission page.
- **Refresh errors are not hidden.** For the first seconds LHDN may refuse the status read (`403`). The submit actions then leave the document Submitted and the job retries; a job that keeps failing ends in `failed_jobs`. The Refresh button shows LHDN's error in a toast.
- **Rejected documents.** A document LHDN rejects is Invalid. It does not count as active, so staff can correct the buyer and submit the folio or AR invoice again with the same number (verified on preprod). The submission page lists LHDN's reasons from each failed validation step.
- **A folio on an e-invoice stays closed.** LHDN does not allow an issued e-invoice to change: within 72 hours of acceptance it can be cancelled and issued again, and after that only a credit, debit or refund note adjusts it (LHDN e-Invoice General FAQ, questions 47 to 51, updated 4 Sep 2026). So `ReopenFolio` and Undo check-out refuse a folio whose own e-invoice, or a monthly summary it is in, is Preparing, Being checked or Valid (`folio_has_e_invoice`, `FolioEInvoiceLock`). A late charge goes on a new folio, which gets its own e-invoice; a lower amount takes a credit note; cancelling the e-invoice within 72 hours frees the folio. A Rejected or Cancelled e-invoice does not hold the folio. This is why debit notes are not built: a raise in price on the same stay would need one, and the hotel posts late charges on a new folio instead.
- **Buyer snapshot.** A submission stores the buyer exactly as LHDN received it (alpha-3 country, foreign TIN filled in). Credit notes reuse it, so LHDN sees the same buyer (`DR308` otherwise).

## Buyer Resolution Rules

1. **B2B Companies**: Requires Company TIN and Business Registration Number (BRN).
2. **Domestic Individual Guests (Malaysian)**: Requires Personal TIN or NRIC (MyKad). Stays without a guest TIN can be consolidated monthly under `EI00000000010`. Individual statutory e-invoices require the buyer's personal TIN.
3. **Foreign Guests**: Requires Passport number with Foreign Buyer TIN `EI00000000020`.
4. **General Public Consolidated**: Requires TIN `EI00000000010`, BRN `NA`, classification code `004`, and state code `17` (Not applicable).

> [!IMPORTANT]
> LHDN strictly forbids submitting non-consolidated individual invoices using `EI00000000010`. Doing so returns validation error `ERR228`. Individual hotel guests must be identified by NRIC, Passport, or personal TIN.

## Cancellation & Credit Note Rules

- **72-Hour Cancellation Window**: LHDN allows cancellation strictly within 72 hours from `validated_at`. A cancellation reason is mandatory.
- **Credit Note (`02`)**: After 72 hours, or if an adjustment is required without cancelling the entire invoice, a Credit Note must be issued referencing the original invoice UUID and code number in `BillingReference`.
- **Cancellation Lockout**: If an active Credit Note references an e-invoice, the original e-invoice cannot be cancelled.

## Monthly B2C Consolidation Rules

For guests who do not request an individual e-invoice upon check-out:

1. **Window**: Consolidated documents must be submitted within 7 calendar days after month-end.
2. **Chunking**: LHDN allows a maximum of 100 stays per consolidated document. Stays in excess of 100 are automatically chunked into consecutive batches.
3. **RM 10,000 Threshold Exemption**: Any stay whose total charges exceed RM 10,000 cannot be consolidated into a batch and must be submitted individually under LHDN regulations. Folios submitted in consolidated batches are recorded in `e_invoice_submission_folios` to prevent double-filing.
4. **Stay amount**: charges, adjustments and taxes that were not reversed. Payments are left out, because a closed folio always balances to 0.
5. **Rejected batches**: stays in an Invalid batch are picked up again by the next run. A stay in an active batch cannot get an individual e-invoice until that batch is cancelled.
6. **Scheduled Automation**: `App\Jobs\MonthlyConsolidationJob` runs automatically on the 1st of every month at 04:00 (Asia/Kuala_Lumpur) for properties with `consolidation_enabled = true`.

## Front Desk Issuance

- Closed folios can be submitted individually by front desk staff via the **Issue e-invoice** action in the folio tab.
- A dedicated sheet (`IssueFolioEInvoiceDialog`) allows staff to input or verify the guest's statutory tax identification (TIN, NRIC, or Passport), billing address, and contact details before transmitting to LHDN.
- Once submitted, the folio tab displays the live e-invoice status badge with a direct link to the submission details.
- **Who sends what.** A stay billed to a company (a live City ledger invoice) is e-invoiced from the City ledger invoice page (**Send e-invoice to LHDN**), never from the folio or the monthly summary, so LHDN gets it once. A folio in a monthly summary shows **In monthly summary** and offers no e-invoice of its own.
- **Checked before sending.** Nothing goes to LHDN while a detail is missing. The refusal names it and where to add it (`MissingEInvoiceDetails`): the hotel's tax number, email or city (E-invoice settings), the guest's tax number, IC or passport, or the company's tax number, registration number or billing city (the company record). A Malaysian guest needs both a tax number and an IC: LHDN checks one against the other (`ERR206`).
- **The answer is reported as it is.** After sending, the toast says whether LHDN accepted it, is still checking it, or rejected it, with the first reason (`SubmissionToast`). A rejected send is an error, not a success.
- **LHDN not reachable.** A timeout or dropped connection is recorded as a send that did not reach LHDN ("Submit again in a few minutes"), not a crash.
- **Credit notes** cannot take off more than what is left on the e-invoice after earlier credit notes.

## Checking a tax number

LHDN has two lookups (MyInvois SDK: Validate Taxpayer's TIN and Search Taxpayer's TIN). `TaxNumberCheck` uses them only when staff ask, because LHDN throttles a client that checks before every submission (about 60 calls a minute per Client ID). A property gets 20 checks a minute.

| Where                                                                                 | What it does                                                                                                                                                                                                                                      |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Check with LHDN** in the Issue e-invoice form                                       | Validates the tax number against the IC, passport or registration number. On a mismatch it looks up the tax number LHDN holds for the ID, and **Use IG…** puts it in the form.                                                                    |
| **Find the tax number with LHDN** on a rejected e-invoice (an `ERR2…` taxpayer error) | Looks up the tax number for the ID that was sent. **Submit again with IG…** sends the stay again with it and every other detail unchanged (`ResubmitWithTaxNumber`). For a City ledger e-invoice it says to put the number on the company record. |

Answers: confirmed, another tax number found, no tax number for the ID, LHDN not reachable, too many checks. Preprod returns 404 for an unknown pair and 400 for a malformed one (verified 11 Nov 2026); it holds no real taxpayers, so a match was not seen there.

## Staff pages

Finance › E-invoicing › **E-invoices** is written for owners, managers and finance staff, not for developers.

- **List**: the City ledger Index layout. Search finds the number, the guest or company name, the reservation number and the City ledger invoice number. Filters: type (E-invoice, Credit note, Monthly summary) and status. A rejected e-invoice shows one alert above the table, with **Show rejected**. Rows open the detail page; they carry no actions.
- **Detail**: one notice says what happened and what to do next, with dates and amounts in bold. Tiles show the total with tax, the stay or month, the reservation and the **LHDN reference** (the one ID LHDN support asks for). **Billed to** and **Credit notes** sit side by side on a wide screen, and **History** is collapsed.
- **Rejections** are explained by `LhdnRejectionReasons`: each LHDN code becomes a plain reason and a fix (`ERR206`: the IC does not match the tax number; for a company buyer, the registration number). An unknown code shows LHDN's own sentence without its developer link. Each reason also says whose details to fix (`where`). LHDN's field path decides first: a field under `AccountingSupplierParty` is the hotel's, one under `AccountingCustomerParty` is the buyer's.
- **Fixing a rejection**: the buttons go where the rejected details are kept.

    | Rejected                        | Where                                                   | Button                                                                                                           |
    | ------------------------------- | ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
    | Buyer, stay e-invoice           | The folio's **Issue e-invoice** form                    | **Fix and submit again** opens the folio with the form open, filled with what was sent and LHDN's reasons on top |
    | Buyer, City ledger invoice      | The company record (tax numbers, billing address)       | **Edit company details**, then **Submit again** on the e-invoice page                                            |
    | Buyer, credit note              | The original e-invoice (a credit note copies its buyer) | **Issue a new credit note** opens the original with the credit note form open                                    |
    | Hotel details or MyInvois login | E-invoice settings                                      | **E-invoice settings**, then submit again                                                                        |
    | LHDN could not be reached       | Nothing to fix                                          | **Submit again**                                                                                                 |
    | Monthly summary                 | E-invoice settings when the hotel details were rejected | **Submit the month again**                                                                                       |

    The resubmit buttons show only while nothing newer has replaced the rejected e-invoice. Corrections typed in the folio form go to LHDN only; they do not update the guest profile.

- **Email to guest** (valid e-invoices, `einvoice.manage`): a Dialog with the guest's address and a preview of the email. `EmailEInvoiceToGuest` sends `EInvoiceForGuest` with the property's wording from [Settings › Emails](../04-notifications/04-guest-emails.md) and logs `einvoice.emailed`.
- **Credit notes** show the 3 latest, with **Show all**, under "Credited X of Y".
- **Privacy**: the page never receives the UBL document or LHDN's raw replies, and shows only the last four characters of the buyer's IC, passport or registration number. `SubmissionView` builds what the page gets.
- **Statuses**: Preparing, Being checked, Valid, Rejected, Cancelled.
- Times are stored as plain `timestamp` like the rest of the schema, and shown in the property's time zone.

## Permissions

- `einvoice.view` (`EInvoiceView`): View e-invoice dashboard, submission status, and payload details. Assigned to `Manager` and `Finance`.
- `einvoice.manage` (`EInvoiceManage`): Submit folios/AR invoices, cancel submissions, issue credit notes, run manual consolidations, and edit credentials. Assigned to `Manager` and `Finance`.
