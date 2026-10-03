# Payments and refunds

## Purpose

Record money received and refunded, and post it to the folio.

## Data model

```
payments    id, property_id, folio_id, payment_method_id, amount,
            purpose (PaymentPurpose), status (PaymentStatus), reference (nullable),
            refund_of_id (nullable), receipt_number (nullable),
            folio_line_id, surcharge_line_id (nullable),
            received_by, received_at, timestamps
            checks: amount > 0; a refund has no receipt number;
            unique (property_id, receipt_number); unique folio_line_id
```

- `amount` is always positive. For a payment it is the **total collected** (the amount paid on the bill, plus any surcharge and its tax). For a refund it is the amount given back.
- `folio_line_id` is the payment line on the folio (negative for a payment, positive for a refund). `surcharge_line_id` is the surcharge charge line, when there is one.
- A refund copies the `purpose` and the `payment_method_id` of the payment it refunds.
- A payment row is never updated after it is created. Its `status` is Completed.

## Enums

- `PaymentPurpose`: Advance, Settlement, SecurityDeposit.
- `PaymentStatus`: Pending, Completed, Failed. **Only Completed is created for now.** Pending, Failed and `gateway_payload` (jsonb) wait for an online gateway. The night audit's pending-payments blocker has nothing to block until then.

## Actions

### `RecordPayment`

`(User $actor, Reservation $reservation, ?Folio $folio, ?ReservationUnit $unit, NewPaymentData $data): PaymentResult`. Give a folio or a unit. Exactly one.

- **A payment before check-in (a deposit):** a payment is a posting, and a folio is created on the first posting. With a unit and no folio, the Action calls `CreateFolio` for that unit, then posts. Nothing creates a folio to display one.
- **A unit that already has a folio:** the Action locks the unit row, finds the unit's own folio and posts to it. It does not refuse, so two quick clicks on Record payment never make two folios.
- `NewPaymentData`: `payment_method_id`, `purpose`, `amount` (the amount paid on the bill), `reference`, `idempotency_key` (a UUID sent by the form, as `manual:{uuid}` is for charges).

| Check, in order                                                            | Refusal                                                          |
| -------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| A business date exists                                                     | `no_business_date`                                               |
| The folio (or the unit) belongs to the reservation and the property        | 404 in the route                                                 |
| The folio is Open                                                          | `folio_not_open`                                                 |
| The method is active, the property's, and not City ledger or OTA collected | `method_not_available`                                           |
| The amount is greater than 0 with at most two decimals                     | `amount_invalid`                                                 |
| A method with `requires_reference` has a reference                         | `reference_required`                                             |
| The tax on the surcharge can be calculated                                 | the `TaxCalculator` refusal (for example `nationality_required`) |

- One transaction, with the folio row locked (the unit row first when a unit is given). A refusal writes and logs nothing.
- Order of writes: the folio when needed, the receipt number, the surcharge lines, the payment line, then the `payments` row with `folio_line_id` and `surcharge_line_id`. The row is inserted once and never updated.
- **Surcharge:** `Fixed` is the method's value. `Percent` is that percent of the amount. The fee posts as a charge line on the **same folio** with the method's surcharge charge item, and tax on it follows the charge item's code (`TaxCalculator`, the booker's nationality). The payment line covers `amount + fee + tax`, so the balance falls by the amount entered. This is how wastays collects it (`post_configured_payment.rb`).
- **No `PostCharge` for the fee.** `PostCharge` needs a CheckedIn or CheckedOut unit, and a deposit has none. The line-writing core of `PostCharge` (tax calculation, the charge line and its tax children, for a given folio) moves into an Action `PostFolioCharge`. `PostCharge` keeps its own checks and calls it, and so does `RecordPayment`.
- Keys use the form's UUID, not the payment id (the payment row needs its line first): payment line `payment:{uuid}`, fee `payment:{uuid}:surcharge`, its tax lines `payment:{uuid}:surcharge:tax:{tax_id}`. A repeated key finds the payment line, then the payment by `folio_line_id`, and returns it. No column stores the key.
- `posting_date` is the business date. The payment line's code is the method's own transaction code.
- The receipt number comes from `document_sequences` (`DocumentType::Receipt`) in the same transaction. A rolled-back payment gives its number back.
- Overpayment is allowed. The balance can go negative (a credit), for example a deposit before any charge.
- Logs `payment.recorded` on the reservation.

### `PaymentQuote` (service)

`(Property, PaymentMethod, amount)`: the surcharge, its tax and the total to collect. It writes nothing. The Dialog shows it before the user confirms, and `RecordPayment` calculates again.

### `RefundPayment`

`(User $actor, Payment $payment, string $amount, ?string $reference, string $idempotencyKey): PaymentResult`. A refund is a payment row with `refund_of_id`. Its folio line is **positive** and uses the system `Refund` code.

| Check, in order                                                  | Refusal                  |
| ---------------------------------------------------------------- | ------------------------ |
| A business date exists                                           | `no_business_date`       |
| The payment is a Completed payment, not a refund                 | `not_refundable`         |
| The folio is Open                                                | `folio_not_open`         |
| The amount is greater than 0 with at most two decimals           | `amount_invalid`         |
| The amount is at most the payment minus the refunds already made | `refund_exceeds_payment` |

- A refund gives money back from the collected total. **The surcharge is not refunded.** It is a charge on the folio, and staff reverse it by hand with `ReverseLine` when the fee must go too.
- Key: `refund:{uuid}`, from the form's UUID. A repeated key returns the existing refund. A refund has no receipt number.
- Logs `payment.refunded` on the reservation.

## Rules

- A deposit is a payment with a purpose. No deposit tables.
- **A payment line is corrected only by a refund.** `ReverseLine` and `TransferLines` refuse a line that a `payments` row owns through `folio_line_id` (`payment_line`). Otherwise a reversed payment could still be refunded, and a transferred one would leave `payments.folio_id` on the old folio. The surcharge line is an ordinary charge and can be reversed or transferred. Moving a deposit to the Master folio is decided with [check-out](../18-front-desk/01-check-in-and-check-out.md).
- A payment with the SecurityDeposit purpose uses the method's own code. The system `SECURITY_DEPOSIT` code stays unused.
- The receipt PDF is rendered on demand from payment + folio with dompdf (see [Receipt](#receipt)). It is not stored.
- City ledger (`DirectBill`) and OTA collected methods are not offered yet. `DirectBill` needs an AR invoice ([AR invoices](../15-city-ledger/02-ar-invoices.md)) and OTA collected comes with [Booking ingestion](../21-channels/05-booking-ingestion.md).
- Card, Bank transfer and E-wallet require a reference by default ([Payment methods](../06-catalogues/04-payment-methods.md)).

## Permissions

| Permission       | Allows                               | Default roles                       |
| ---------------- | ------------------------------------ | ----------------------------------- |
| `payment.record` | Record a payment, download a receipt | Owner, Manager, Front desk, Finance |
| `payment.refund` | Refund a payment                     | Owner, Manager, Finance             |

A migration syncs the two permissions, and both rows go into [Roles and permissions](../03-staff/02-roles-and-permissions.md).

## UI (folio tab)

- **Record payment** button in the folio header, on an Open folio, with `payment.record`. A Dialog (method, purpose, amount, reference when required) shows the surcharge, its tax and the **total to collect** from the quote route before the user confirms.
- The "not started" rail entry gets Record payment, and only that: it creates the unit's folio on the first payment ([Folio page](../13-folio/05-folio-page.md)).
- A payment row shows its method and reference. Its row menu has **Refund** (`payment.refund`, a Completed payment with an amount left to refund). A Dialog takes the amount (default: what is left) and an optional reference.
- A refund row is tagged "Refund".
- Payment and refund rows get no Reverse or Transfer action (see the `payment_line` rule).

## Receipt

A table-based Blade PDF layout (dompdf has no flex or grid, see [design system](../design-system/11-accessibility-performance-pdfs.md#pdfs)). It shows the property, the receipt number, the date, the guest, the folio number, the method, the reference, the amount paid, the surcharge and the total collected. Route `GET …/payments/{payment}/receipt` with `payment.record`. A refund has no receipt.

## Build order

Three commits, in order.

1. **Backend:** `payments` table with CHECKs, `PaymentPurpose`, `PaymentStatus`, `Payment` with its factory, `PostFolioCharge` extracted from `PostCharge`, `RecordPayment`, `RefundPayment`, `PaymentQuote`, `PaymentResult` and the two permissions with a syncing migration. No UI.
2. **Folio tab:** the routes, the Dialogs, the payment rows and the Refund row action.
3. **Receipt PDF:** the layout, the route and the download link on a payment row.

## Not in this item

The AR invoice for City ledger, OTA collected payments, the Stay View Record payment button ([Stay View](../18-front-desk/03-stay-view.md)), the Cards and List money strip, online gateways with Pending payments.

## Lessons from wastays

`deposits`, `deposit_movements`, `receipts` (3 jsonb columns) and `payment_transactions` are replaced by one table.
