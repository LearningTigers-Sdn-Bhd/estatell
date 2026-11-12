# LHDN MyInvois E-Invoicing

## Purpose

Automate statutory e-invoicing compliance with the Inland Revenue Board of Malaysia (LHDN / Lembaga Hasil Dalam Negeri Malaysia) via the MyInvois Open API gateway. It supports live submission of closed reservation folios, City Ledger accounts receivable (AR) invoices, monthly B2C consolidations, and credit notes.

## Scope

| Now                                                                        | Later                                                  |
| -------------------------------------------------------------------------- | ------------------------------------------------------ |
| Submission of closed reservation folios (`01` Invoice)                     | Self-billed e-invoices (`11`) for OTA commissions      |
| Submission of City Ledger AR invoices (`01` Invoice)                       | Debit Notes (`03`)                                     |
| Credit (`02`) and Refund Notes (`04`) referencing the original UUID        | Webhook receiver for real-time buyer rejections        |
| Monthly B2C consolidation batching with General Public TIN `EI00000000010` | Automated XML/UBL export download button               |
| 72-hour cancellation window enforcement on LHDN                            |                                                        |
| Unsigned v1.0 documents (SHA-256 document hash)                            | Digital signing (v1.1), once `DocumentSigner` is fixed |
| Mock, Sandbox, and Production gateway environments                         |                                                        |
| Behind the `EInvoicing` Pennant feature flag, off by default               |                                                        |

## Feature gate

- Pennant feature `EInvoicing` (`einvoicing`), toggled per property by superadmins on the property features screen (`/admin/properties/{property}`).
- When inactive, every e-invoice route answers 404 through the `einvoicing` middleware (`EnsureEInvoicingIsActive`): settings, the Finance e-invoice pages, and the folio and AR invoice submit and cancel actions. Sidebar items in Settings and Finance are hidden, and the folio tab hides **Issue e-invoice**.
- `SubmitFolioEInvoice` also refuses while the flag is off, so Send at check-out does nothing. `MonthlyConsolidationJob` skips the property.
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
- **Status refresh.** LHDN validates in the background. After a submission, if LHDN still says "Submitted", `RefreshEInvoiceStatusJob` is queued. It checks again after 30 s, 1, 2, 3, 5, 5 and 10 minutes (about 25 minutes in all). After that, staff can press Check status on the submission page.
- **Refresh errors are not hidden.** For the first seconds LHDN may refuse the status read (`403`). The submit actions then leave the document Submitted and the job retries; a job that keeps failing ends in `failed_jobs`. The Refresh button shows LHDN's error in a toast.
- **Rejected documents.** A document LHDN rejects is Invalid. It does not count as active, so staff can correct the buyer and submit the folio or AR invoice again with the same number (verified on preprod). The submission page lists LHDN's reasons from each failed validation step.
- **A folio on an e-invoice stays closed.** LHDN does not allow an issued e-invoice to change: within 72 hours of acceptance it can be cancelled and issued again, and after that only a credit, debit or refund note adjusts it (LHDN e-Invoice General FAQ, questions 47 to 51, updated 4 Sep 2026). So `ReopenFolio` and Undo check-out refuse a folio whose own e-invoice, or a monthly summary it is in, is Preparing, Being checked or Valid (`folio_has_e_invoice`, `FolioEInvoiceLock`). A late charge goes on a new folio, which gets its own e-invoice; a lower amount takes a credit note; cancelling the e-invoice within 72 hours frees the folio. A Rejected or Cancelled e-invoice does not hold the folio. This is why debit notes are not built: a raise in price on the same stay would need one, and the hotel posts late charges on a new folio instead.
- **Amounts are exact.** Every sum is made with `BigDecimal` (`ReportableLines`) and becomes a JSON number only in the finished document (`LhdnAmount`, half up to the sen). The lines an e-invoice reports are the folio's charges, adjustments and taxes that are not reversals and were not reversed; a reversal is always on the folio of the line it reverses.
- **Buyer snapshot.** A submission stores the buyer exactly as LHDN received it (alpha-3 country, foreign TIN filled in). Credit notes reuse it, so LHDN sees the same buyer (`DR308` otherwise).

## Buyer Resolution Rules

1. **B2B Companies**: Requires Company TIN and Business Registration Number (BRN).
2. **Domestic Individual Guests (Malaysian)**: Requires Personal TIN or NRIC (MyKad). Stays without a guest TIN can be consolidated monthly under `EI00000000010`. Individual statutory e-invoices require the buyer's personal TIN.
3. **Foreign Guests**: Requires Passport number with Foreign Buyer TIN `EI00000000020`.
4. **General Public Consolidated**: Requires TIN `EI00000000010`, BRN `NA`, classification code `004`, and state code `17` (Not applicable).

> [!IMPORTANT]
> LHDN strictly forbids submitting non-consolidated individual invoices using `EI00000000010`. Doing so returns validation error `ERR228`. Individual hotel guests must be identified by NRIC, Passport, or personal TIN.

## Hotel details

`SupplierDetails` resolves the hotel (the supplier on every document) once for all four builders: each detail from E-invoice settings, else from the property. Nothing is made up. `missing()` lists what is empty or malformed, and:

- **Switching e-invoicing on** is refused until it is empty (errors on each settings field).
- **Every send** checks it again (`MissingEInvoiceDetails::hotel`), so clearing a detail later stops sending instead of filling in a placeholder.
- **The settings page** shows "E-invoices cannot go out until these are fixed" when e-invoicing is already on with gaps.

Required: registered name; tax number (letters then digits); company registration number (`supplier_brn`, 12 digits, read out of forms such as `201901012345 (123456-A)`); 5-digit MSIC code; email; phone; street address; city; 5-digit postcode; state (the property's state may be a name such as "Sabah"; `MalaysiaStates::codeFor` turns it into LHDN's code). A hotel with an enabled SST tax must give its SST registration number, and one with tourism tax (`TTX`) its tourism tax registration number; both are sent as supplier identifications (verified on preprod, 11 Nov 2026). The business description defaults to "Hotel and accommodation services".

## Tax

Each tax line's LHDN type comes from the tax catalogue (`LhdnTaxType`): `SST` is Service Tax `02`, `TTX` Tourism Tax `03`, any other levy (for example a council heritage fee) `06` Not applicable. A stay e-invoice reports each line's taxes; the monthly summary reports each stay's charges before tax with its taxes by type, an exempt (`E`) subtotal for stays without tax, and document totals by type (verified on preprod, 11 Nov 2026). Summaries sent before this fix reported SST inside the stay amount as exempt.

The summary page lists its stays (reservation, guest, folio, closed date, before tax, tax, total) with a totals row, read from the document LHDN received, so managers can check each stay against its folio.

## Sending to LHDN

`SendEInvoiceToLhdn` sends every document: stay, City ledger, credit and refund notes, and each part of a monthly summary.

- **One record per document, made under a lock.** The checks ("does this stay already have an e-invoice?") and the Pending record run in a transaction that holds the property's e-invoice settings row locked (`LockEInvoiceRecords`). Two clicks, two staff, or a folio send against the monthly summary cannot both pass the checks. Cancelling runs under the same lock, so a credit note cannot be saved on an e-invoice while it is being cancelled.
- **LHDN is called after the record commits**, outside any transaction, so the record of what was sent survives whatever happens after the call.
- **The send time is saved before the call.** A Pending document with a send time and no LHDN id is one whose answer was lost.

### Unconfirmed sends

| What happened                                                                                          | LHDN may have it?    | Status                                | What staff see                                                         |
| ------------------------------------------------------------------------------------------------------ | -------------------- | ------------------------------------- | ---------------------------------------------------------------------- |
| LHDN accepted it                                                                                       | Yes                  | Being checked, then Valid or Rejected | The answer                                                             |
| LHDN rejected the document                                                                             | No                   | Rejected                              | LHDN's reasons and the fix                                             |
| The login failed (`MyInvoisLoginFailed`)                                                               | No, nothing was sent | Rejected, no send time                | "LHDN refused the hotel's MyInvois login" or "We could not reach LHDN" |
| LHDN refused the request (400, 403, 429, other 4xx)                                                    | No                   | Rejected                              | The reason, then send again                                            |
| Timeout, dropped connection (status 0 or 408), LHDN server error (5xx), or `DuplicateSubmission` (422) | Maybe                | Pending, badge **Waiting for LHDN**   | "Do not send it again: we are checking with LHDN"                      |

- **Looking it up.** `RefreshEInvoiceStatus` looks for a Waiting document by its number among the documents the hotel sent from 2 minutes before to 30 minutes after its send time (Search Documents API, `GET /api/v1.0/documents/search`, direction Sent, its document type). Found: it takes LHDN's id and reads its status like any other. `RefreshEInvoiceStatusJob` looks it up first after 1 minute, then on its usual schedule; staff can press **Check status** at any time.
- **Not found after 10 minutes.** LHDN's duplicate check covers 10 minutes, and LHDN does not say how soon a new document shows in its search. So an empty look-up after 10 minutes is shown as "LHDN still has no record of it", not as proof it never arrived: the document is marked Rejected so it can be sent again, and staff are told to look for its number in the MyInvois portal first.
- **Never sent.** A Pending document with no send time was never sent (the call was never made). After 2 minutes **Check status** marks it Rejected ("It was never sent to LHDN") so it can be sent.
- **Search limits.** LHDN throttles the search to about one call every 5 seconds per taxpayer, allows at most 31 days per search and returns at most 100 documents a page, so it is only used for this look-up.

## Send at check-out

With **Send at check-out** on (`auto_submit_on_checkout`), a stay's e-invoice goes to LHDN by itself when its folio closes by any path: check-out, group check-out, the kiosk, or the folio tab's Close. `CloseFolio` raises `FolioClosed`; `QueueFolioEInvoiceOnClose` queues `SubmitFolioEInvoiceJob` after the close commits, so check-out never waits on LHDN.

- **Only for a guest with their own tax number.** A paying guest with no tax number, or with LHDN's general foreign number, goes into the monthly summary. A company-paid folio is e-invoiced from its City ledger invoice or by staff.
- **Refusals wait for staff.** The job runs once. A refusal (such as a missing city) is logged, and the stay can still be sent from the folio or goes into the monthly summary. A send whose answer was lost is looked up, never sent again by the job.

## Cancellation & Credit Note Rules

- **Refund note (`04`)**: LHDN requires a refund note for money paid back to the buyer, except an overpayment, a payment made by mistake or a returned security deposit (General FAQ question 54). The **Issue credit or refund note** form asks "Was money paid back to the guest?": No issues a credit note, Yes a refund note. Both reference the original, copy its buyer, and together cannot take off more than the e-invoice's total.

- **72-Hour Cancellation Window**: LHDN allows cancellation strictly within 72 hours from `validated_at`. A cancellation reason is mandatory.
- **Credit Note (`02`)**: After 72 hours, or if an adjustment is required without cancelling the entire invoice, a Credit Note must be issued referencing the original invoice UUID and code number in `BillingReference`.
- **Cancellation Lockout**: If a credit or refund note LHDN has or is checking references an e-invoice, the original e-invoice cannot be cancelled. A rejected or cancelled note does not stop it.
- **What is left to credit** is the e-invoice's total less its credit and refund notes that LHDN has or is checking, to the sen (`SubmissionRules::creditRemaining`). It is checked under the e-invoice lock.

## Monthly B2C Consolidation Rules

For guests who do not request an individual e-invoice upon check-out:

1. **Window**: Consolidated documents must be submitted within 7 calendar days after month-end.
2. **Chunking**: LHDN allows a maximum of 100 stays per consolidated document. Stays in excess of 100 are automatically chunked into consecutive batches.
3. **Stays over RM10,000**: since 1 January 2026 LHDN does not allow a sale over RM10,000 in a consolidated e-invoice. Such a stay needs its own e-invoice with the guest's own tax number, because LHDN refuses the general public TIN on an individual e-invoice (`ERR228`, "General TIN (010) is not allowed for NON-consolidated e-invoice", seen in wastays). `StaysNeedingOwnEInvoice` lists closed stays over the limit with no e-invoice (since e-invoicing was set up): the e-invoice list shows them in an alert linking to each folio's Issue e-invoice form, the folio shows a red notice, the summary toast counts the ones left out, and a missing tax number says to find it from the IC with **Check with LHDN**. Folios submitted in consolidated batches are recorded in `e_invoice_submission_folios` to prevent double-filing, each with the `line_number` of its line in the document, so the summary page reads each stay's amounts from its own line. Summaries sent before `line_number` existed are read by the folio number in the line text.
4. **Company payers**: a company-paid stay that is not billed to the City ledger goes into the general public summary like any guest who did not ask for an e-invoice. If the company asks for one, issue it from the folio before the month's summary.
5. **Stay amount**: charges, adjustments and taxes that were not reversed. Payments are left out, because a closed folio always balances to 0.
6. **Rejected batches**: stays in an Invalid batch are picked up again by the next run. A stay in an active batch cannot get an individual e-invoice until that batch is cancelled.
7. **Scheduled Automation**: `App\Jobs\MonthlyConsolidationJob` runs automatically on the 1st of every month at 04:00 (Asia/Kuala_Lumpur) for properties with `consolidation_enabled = true`. It covers the month before the current one in each hotel's own time zone (`SummaryMonth`). One hotel's gap or error is logged and does not stop the hotels after it.
8. **All or nothing.** Choosing the stays, building every part and saving their Pending records run in one locked transaction. If any part cannot be built, nothing is saved or sent and staff see why; then each part is sent on its own.

## Front Desk Issuance

- Closed folios can be submitted individually by front desk staff via the **Issue e-invoice** action in the folio tab.
- A dedicated sheet (`IssueFolioEInvoiceDialog`) allows staff to input or verify the guest's statutory tax identification (TIN, NRIC, or Passport), billing address, and contact details before transmitting to LHDN.
- Once submitted, the folio tab displays the live e-invoice status badge with a direct link to the submission details.
- **Who sends what.** A stay billed to a company (a live City ledger invoice) is e-invoiced from the City ledger invoice page (**Send e-invoice to LHDN**), never from the folio or the monthly summary, so LHDN gets it once. A folio in a monthly summary shows **In monthly summary** and offers no e-invoice of its own.
- **Checked before sending.** Nothing goes to LHDN while a detail is missing. The refusal names it and where to add it (`MissingEInvoiceDetails`): the hotel's tax number, email or city (E-invoice settings), the guest's tax number, IC or passport, or the company's tax number, registration number or billing city (the company record). A Malaysian guest needs both a tax number and an IC: LHDN checks one against the other (`ERR206`).
- **The answer is reported as it is.** After sending, the toast says whether LHDN accepted it, is still checking it, or rejected it, with the first reason (`SubmissionToast`). A rejected send is an error, not a success.
- **LHDN not reachable.** A failed login is recorded as not sent ("Submit again in a few minutes"). A send whose answer was lost waits for LHDN and is looked up: see [Unconfirmed sends](#unconfirmed-sends).
- **Credit notes** cannot take off more than what is left on the e-invoice after earlier credit notes.
- **Nothing to report.** A folio whose charges were all reversed (total RM0) offers no e-invoice.
- **City ledger stays already with LHDN.** A City ledger invoice whose stay is already in a monthly summary, or has its own e-invoice, shows that instead of **Send e-invoice to LHDN**, and sending is refused (`already_reported`).
- **Rejections that were fixed.** A rejected document that was sent again (or whose stay went into a monthly summary, or a later credit or refund note on the same e-invoice) shows "Rejected, sent again" with a link to what replaced it, offers no fixes, and is not counted in the list's rejected alert.
- **Debit notes are not built.** A late extra (minibar, damage) is a new sale on a new folio with its own e-invoice, and an error found within 72 hours is fixed by cancelling and sending again. A debit note is only for an undercharge on the same stay found after 72 hours; building it also needs a rule so the money collected for it is not e-invoiced a second time.

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
- **Privacy**: the page never receives the UBL document or LHDN's raw replies, and shows the buyer's full IC, passport or registration number only to staff with `guests.view-documents` (others see the last four characters, as on the guest profile). `SubmissionView` builds what the page gets.
- **Statuses**: Preparing, Being checked, Valid, Rejected, Cancelled.
- Times are stored as plain `timestamp` like the rest of the schema, and shown in the property's time zone.

## Permissions

- `einvoice.view` (`EInvoiceView`): View e-invoice dashboard, submission status, and payload details. Assigned to `Manager` and `Finance`.
- `einvoice.manage` (`EInvoiceManage`): Submit folios/AR invoices, cancel submissions, issue credit notes, run manual consolidations, and edit credentials. Assigned to `Manager` and `Finance`.
