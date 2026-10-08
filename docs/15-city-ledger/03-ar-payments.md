# AR payments and allocation

## Purpose

Record company payments and match them to invoices.

## Data model

```
ar_payments      id, property_id, company_id, payment_method_id, amount, received_on, reference, notes,
                 receipt_number, idempotency_key, received_by, voided_at (nullable), voided_by (nullable), timestamps
                 UNIQUE (property_id, receipt_number), UNIQUE (property_id, idempotency_key)
                 CHECK amount > 0
ar_allocations   id, ar_payment_id, ar_invoice_id, amount, created_by,
                 reversed_at (nullable), reversed_by (nullable), reversal_reason (nullable), timestamps
                 CHECK amount > 0
```

Payments and allocations are never edited. Only the void and reversal columns are set, once.

## Rules

- **Methods:** an active payment method of the property, except OTA collected and City ledger. There is no surcharge, because there is no folio.
- **Receipt number:** from the property's `Receipt` sequence, the same one folio receipts use, so receipts run in one series. `ar_payments` has its own unique rule per property, and the shared sequence keeps the two tables from clashing.
- **Date:** `received_on` is the business date unless staff pick an earlier one. A future date is refused (`date_in_future`), and so is a property with no business date (`no_business_date`).
- **Repeat:** the form's UUID is the `idempotency_key`. A repeat returns the existing payment and writes nothing.
- **Balances** are calculated, never stored:
    - invoice balance = amount + credit notes that are not Void - active allocations;
    - payment unallocated = amount - active allocations (0 once voided);
    - company balance = open invoice balances - unallocated payments.
- Unallocated money stays as credit on the company.
- **Invoice status:** an invoice becomes Settled when its balance reaches 0, and Open again when an allocation is reversed.
- **Void invoice** ([AR invoices](02-ar-invoices.md#rules)) is refused while the invoice has active allocations.
- **Void payment:** allowed only while the payment has no active allocations. Reverse them first.
- **Which place records company money.** Money received before check-out (an early payment for a stay) is an Advance on the Master folio ([Payments](../14-payments/01-payments-and-refunds.md)). Money received after the invoice is an AR payment here. Recording one transfer in both places counts it twice.
- **Reference warning.** As the reference is typed, the Dialog asks `FolioPaymentReferences` (`app/Services/CityLedger/`) whether the same reference was already recorded, and shows a warning under the field. It never blocks the payment. A match is exact after trim, ignoring case; a blank reference never matches; refunds and voided AR payments never match. It looks at:
    - Completed folio payments on a folio the company pays, or on any folio of a reservation of the company: "Reference MBB-88231 was already recorded on folio F-0031 (receipt R-1002) on 08 Oct. Check it is not the same money before you record it.";
    - the company's other AR payments: "Reference MBB-88231 was already recorded as AR payment RC-0045 on 08 Oct. Check it is not the same money before you record it."
    - With more matches it adds "and 2 more." The date is the business posting date (folio) or `received_on` (AR).
- The warning is one-way: Record payment on a folio does not check AR payments. The usual order is the advance first and the bank transfer later.
- The credit limit is not checked here. It comes with [Credit control](04-credit-control-and-reports.md).

## Actions

| Action                  | Does                                                                                                                     | Refusals                                                                                                                 |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------ |
| `RecordArPayment`       | Records the payment and its receipt number. With `allocate` true it runs `AutoAllocateArPayment` in the same transaction | `method_not_available`, `amount_invalid`, `reference_required`, `date_in_future`, `no_business_date`                     |
| `AllocateArPayment`     | `(User, ArPayment, array of invoice id => amount)`: allocates to several invoices at once. All lines or none             | `payment_not_allocatable`, `invoice_not_allocatable`, `amount_invalid`, `exceeds_invoice_balance`, `exceeds_unallocated` |
| `AutoAllocateArPayment` | Fills the company's open invoices oldest first (by `due_on`, then id). What is left stays as credit                      | `payment_not_allocatable`                                                                                                |
| `ReverseAllocation`     | Marks an allocation reversed with a required reason. The invoice reopens if it was Settled                               | `already_reversed`, `reason_required`                                                                                    |
| `VoidArPayment`         | Voids a payment with no active allocations                                                                               | `payment_has_allocations`, `already_void`                                                                                |

A refusal returns a result, writes nothing and logs nothing. Each success logs on the company: `ar_payment.recorded`, `ar_payment.allocated`, `ar_allocation.reversed`, `ar_payment.voided`.

## Permissions

| Permission             | Allows                                    | Default roles           |
| ---------------------- | ----------------------------------------- | ----------------------- |
| `city-ledger.payment`  | Record a payment, void a payment          | Owner, Manager, Finance |
| `city-ledger.allocate` | Allocate a payment, reverse an allocation | Owner, Manager, Finance |

Viewing the Payments tab and the receipt needs `company.view`.

## Pages

| Page                       | Route                                                                                                                                                                                  | Permission             |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| Company page, Payments tab | `GET .../companies/{company}/payments` (`finance.city-ledger.companies.payments`)                                                                                                      | `company.view`         |
| Record payment             | `POST .../companies/{company}/payments` (Dialog)                                                                                                                                       | `city-ledger.payment`  |
| Allocate                   | `POST .../companies/{company}/payments/{payment}/allocations` (Dialog)                                                                                                                 | `city-ledger.allocate` |
| Reverse allocation         | `POST .../companies/{company}/payments/{payment}/allocations/{allocation}/reverse`                                                                                                     | `city-ledger.allocate` |
| Reference check            | `POST .../companies/{company}/payments/reference-check` (JSON, `finance.city-ledger.companies.payments.reference-check`). POST so a bank reference never goes in a URL. Writes nothing | `city-ledger.payment`  |
| Void payment               | `POST .../companies/{company}/payments/{payment}/void` (AlertDialog)                                                                                                                   | `city-ledger.payment`  |
| Receipt PDF                | `GET .../companies/{company}/payments/{payment}/receipt`                                                                                                                               | `company.view`         |

- **Payments tab:** a **Record payment** button, then the list: receipt number, date, method, reference, amount, unallocated amount and status (Voided is a neutral badge). The row menu has Allocate, Receipt and Void.
- **Record payment Dialog:** method, amount, received on, reference (required when the method requires it), notes and an **Allocate to oldest invoices** tick, on by default. Under the reference, the reference warning (debounced 300 ms, like the payment quote) when there is a match. The Record button is never disabled by it.
- **Allocate Dialog:** the payment's unallocated amount at the top, then the company's open invoices (number, due date, balance) with an amount box each.
- **Invoice page:** an **Allocations** section (payment receipt number, date, amount, status) with **Reverse** on each active allocation, which asks for a reason.
- The tab, the open invoices and the payment methods are read in a constant number of queries (grouped sums, no query per row).
- **Receipt PDF:** titled "Receipt", with the shared letterhead, the company name and "Received against the city ledger", the method, reference, date, amount and who received it. It is rendered on demand and not stored.

## Build order

1. Tables, models, `ArInvoiceBalance` and `VoidArInvoice` changes, `RecordArPayment`, `AllocateArPayment`, `AutoAllocateArPayment` and the two permissions.
2. `ReverseAllocation` and `VoidArPayment`.
3. The Payments tab, the dialogs, the allocations on the invoice page and the receipt PDF.

## Lessons from wastays

`ar_payment_allocation_reversals` becomes three columns on `ar_allocations`.
