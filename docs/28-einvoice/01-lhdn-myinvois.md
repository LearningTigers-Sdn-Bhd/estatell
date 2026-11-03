# LHDN MyInvois E-Invoicing

## Purpose

Automate statutory e-invoicing compliance with the Inland Revenue Board of Malaysia (LHDN / Lembaga Hasil Dalam Negeri Malaysia) via the MyInvois Open API gateway. It supports live submission of closed reservation folios, City Ledger accounts receivable (AR) invoices, monthly B2C consolidations, and credit notes.

## Scope

| Now                                                                                             | Later                                             |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| Submission of closed reservation folios (`01` Invoice)                                          | Self-billed e-invoices (`11`) for OTA commissions |
| Submission of City Ledger AR invoices (`01` Invoice)                                            | Debit Notes (`03`) and Refund Notes (`04`)        |
| Credit Notes (`02`) referencing original validated submission UUID                              | Webhook receiver for real-time buyer rejections   |
| Monthly B2C consolidation batching with General Public TIN `EI00000000010`                      | Automated XML/UBL export download button          |
| 72-hour cancellation window enforcement on LHDN                                                 |                                                   |
| Digital signing with PKCS#12 / XAdES Enveloped Signature (v1.1) or SHA-256 base64 digest (v1.0) |                                                   |
| Mock, Sandbox, and Production gateway environments                                              |                                                   |
| Behind the `EInvoicing` Pennant feature flag, off by default                                    |                                                   |

## Feature gate

- Pennant feature `EInvoicing` (`einvoicing`), toggled per property by superadmins on the property features screen (`/admin/properties/{property}`).
- When inactive, e-invoicing routes return 404, sidebar items in Settings and Finance are hidden, and background jobs skip the property.

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

LHDN supports two document versions:

1. **Version 1.0 (Standard)**: Uses SHA-256 document hashing of normalized JSON base64-encoded in the API payload. Recommended during initial rollout and Sandbox testing.
2. **Version 1.1 (Signed)**: Envelopes a cryptographic signature using an X.509 certificate and RSA private key (`UBLExtensions` block) conforming to XAdES baseline specifications.

Property owners can upload their `.p12` or `.pem` certificate and key in **Settings > E-Invoice**.

## Buyer Resolution Rules

1. **B2B Companies**: Requires Company TIN and Business Registration Number (BRN).
2. **Domestic Individual Guests (Malaysian)**: Requires Personal TIN or NRIC (MyKad). If personal TIN is not provided, fallback `EI00000000000` is used with NRIC.
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
3. **RM 10,000 Threshold Exemption**: Any stay whose total charges exceed RM 10,000 cannot be consolidated into a batch and must be submitted individually under LHDN regulations.
4. **Scheduled Automation**: `App\Jobs\MonthlyConsolidationJob` runs automatically on the 1st of every month at 04:00 (Asia/Kuala_Lumpur) for properties with `consolidation_enabled = true`.

## Permissions

- `einvoice.view` (`EInvoiceView`): View e-invoice dashboard, submission status, and payload details. Assigned to `Manager` and `Finance`.
- `einvoice.manage` (`EInvoiceManage`): Submit folios/AR invoices, cancel submissions, issue credit notes, run manual consolidations, and edit credentials. Assigned to `Manager` and `Finance`.
