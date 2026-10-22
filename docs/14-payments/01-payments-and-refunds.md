# Payments and refunds

## Purpose

Record money received and refunded, and post it to the folio.

## Data model

```
payments    id, property_id, folio_id, payment_method_id, amount,
            purpose (PaymentPurpose), status (PaymentStatus), reference (nullable),
            refund_of_id (nullable), refund_reason (nullable), voided (bool),
            receipt_number (nullable),
            folio_line_id, surcharge_line_id (nullable),
            received_by, received_at, timestamps
            checks: amount > 0; a refund has no receipt number;
            only a refund has a reason or is voided;
            unique (property_id, receipt_number); unique folio_line_id
```

- `amount` is always positive. For a payment it is the **total collected** (the amount paid on the bill, plus any surcharge and its tax). For a refund it is the amount given back.
- `folio_line_id` is the payment line on the folio (negative for a payment, positive for a refund). `surcharge_line_id` is the surcharge charge line, when there is one.
- A refund copies the `purpose` of the payment it refunds. It copies the `payment_method_id` too, unless it goes to [another method](#refund-to-another-method). `refund_reason` holds the reason for another method. `voided` marks a [void](#voidpayment).
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

| Check, in order                                                                            | Refusal                                                          |
| ------------------------------------------------------------------------------------------ | ---------------------------------------------------------------- |
| A business date exists                                                                     | `no_business_date`                                               |
| The folio (or the unit) belongs to the reservation and the property                        | 404 in the route                                                 |
| The folio is Open                                                                          | `folio_not_open`                                                 |
| A SecurityDeposit goes on a Unit folio or a unit entry                                     | `deposit_on_unit_only`                                           |
| An Advance on a Unit folio or unit entry only when Room does not route to the Master folio | `advance_on_master`                                              |
| The method is active, the property's, and not City ledger or OTA collected                 | `method_not_available`                                           |
| The amount is greater than 0 with at most two decimals                                     | `amount_invalid`                                                 |
| A method with `requires_reference` has a reference                                         | `reference_required`                                             |
| The tax on the surcharge can be calculated                                                 | the `TaxCalculator` refusal (for example `nationality_required`) |

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

`quote(Reservation, PaymentMethod, string $amount): PaymentQuoteResult`: the surcharge, its tax and the total to collect. It takes the reservation because the tax on the fee uses the booker's nationality. It writes nothing. A Percent fee is rounded half up once, and a fee that rounds to 0.00 posts nothing. The Dialog shows it before the user confirms, and `RecordPayment` calculates again.

### `RefundPayment`

`(User $actor, Payment $payment, string $amount, ?string $reference, string $idempotencyKey, ?string $serviceDate = null, ?int $manualBatchId = null, ?int $methodId = null, ?string $reason = null, bool $void = false): PaymentResult`. A refund is a payment row with `refund_of_id`. Its folio line is **positive** and uses the system `Refund` code.

| Check, in order                                                                | Refusal                    |
| ------------------------------------------------------------------------------ | -------------------------- |
| A business date exists                                                         | `no_business_date`         |
| The payment is a Completed payment, not a refund                               | `not_refundable`           |
| The folio is Open                                                              | `folio_not_open`           |
| The amount is greater than 0 with at most two decimals                         | `amount_invalid`           |
| The amount is at most the payment minus the refunds already made               | `refund_exceeds_payment`   |
| Another method: it is an active method of the property of a type staff may add | `method_not_available`     |
| Another method: the actor holds `payment.refund-other-method`                  | `other_method_not_allowed` |
| Another method: a reason is given                                              | `reason_required`          |
| Another method: only a cash payment is refunded in cash                        | `refund_method_mismatch`   |
| A void: the payment has no refund yet, so the amount is all of it              | `void_has_refund`          |
| A void: the payment line was posted on the current business date               | `void_not_same_day`        |

- A refund gives money back from the collected total. **The surcharge is not refunded.** It is a charge on the folio, and staff reverse it by hand with `ReverseLine` when the fee must go too.
- Key: `refund:{uuid}`, from the form's UUID. A repeated key returns the existing refund. A refund has no receipt number.
- Logs `payment.refunded` on the reservation (`payment.voided` for a void). The log carries the method and the reason.

#### Refund to another method

- The default is the method of the payment. `$methodId` names another one, only for a user with `payment.refund-other-method`, and only with a reason (`refund_reason`).
- The target is an active method of the property that staff may add (Cash, Card, Bank transfer, E-wallet). City ledger, OTA collected and Old system are never a target.
- **Only a cash payment is refunded in cash.** A Card, Bank transfer or E-wallet payment can go to any other non-cash method, never to Cash. The right does not lift this rule.
- A target equal to the payment's own method is the same as no target: no right and no reason needed.
- The refund row takes the target's `payment_method_id`, so reports and the cash-up count the money under the method that gave it back.

### `VoidPayment`

`(User $actor, Payment $payment, string $idempotencyKey): PaymentResult`. A void fixes a payment recorded by mistake on the same business day. It is `RefundPayment` for the whole payment, to the same method, with `voided` set. It uses `payment.refund`.

- The payment line must be posted on the current business date, and the payment must have no refund yet. A void is never a partial refund.
- The refund line reads "Void payment". The row is tagged Refund like any refund. The surcharge stays, as in a refund: reverse it by hand when the fee must go too.
- Later days use a normal refund.

### `RefundFolioCredit`

`(User $actor, Folio $folio, string $amount, ?int $methodId, ?string $reason, string $idempotencyKey): PaymentResult`. It gives a folio's credit back, without a check-out. It is the **Refund credit** button in the folio header.

- The folio must be Open and its balance below 0. The amount is at most the credit and at most what its payments can give back.
- The parts come from the payments the folio still holds: the newest first, security deposits last, because the guest is still in house. Check-out keeps its own order (deposits first, then the oldest) in `CheckOutSettlement::refundPool()`. Each part goes through `RefundPayment`, with the key `{uuid}:{payment id}`.
- With no method, each part goes back to its own method. With `$methodId`, every part goes to that method, with the rules of [another method](#refund-to-another-method). Any refusal rolls back every part.
- A folio that holds money a payment cannot give back (a City ledger line) offers no refund.

## Rules

- A deposit is a payment with a purpose. No deposit tables.
- **Service date.** `RecordPayment` and `RefundPayment` take an optional `service_date` and `manual_batch_id` from the [Manual Folio Editor](../13-folio/08-manual-folio.md#preview-and-post). The payment line's `service_date` is the day the money was taken; `posting_date` stays the business date.
- **Old system.** A payment taken by the old PMS uses the system **Old system** method: inside the migration window, `folio.manual`, a date that may be before go-live, a required reference, no surcharge and no receipt number. Reports and the cash-up leave it out. See [Migration](../17-onboarding/07-migration.md#old-system-payment-method).
- **A security deposit stays on a unit folio** (`deposit_on_unit_only`): check-out refunds it from the unit's own folio, so on a Master or ThirdParty folio it would never be found. "A security deposit stays on the unit's own folio. Record it on the unit folio."
- **An advance goes where the room charges go** (`advance_on_master`): when `FolioRouter::roomChargesGoToMaster($unit)` is true (the real rules, BillingMode and Manual, for the property's Room revenue code), an Advance on that unit's folio or "not started" entry is refused. A payment line never moves, so it would sit next to no charges while the company is billed in full. "An advance for this room goes on the Master folio, because the Master folio pays the room charges. Record it there." `CheckInCollection` uses the same method, and Collect now never records the taxes when Room routes to the Master (the tax rows are hidden), so check-in never meets this refusal.
- The two purpose checks run after `folio_not_open` and before `method_not_available`. Both map to the form error, because the Purpose field can be hidden. The server does not check the other stage rules below: only these two affect where money sits. It cannot check them: Collect now records the check-in taxes as an Advance on a unit that is already CheckedIn (`CheckInAndCollect`), which the table would forbid.
- **A payment line is corrected only by a refund.** `ReverseLine` and `TransferLines` refuse a line that a `payments` row owns through `folio_line_id` (`payment_line`). Otherwise a reversed payment could still be refunded, and a transferred one would leave `payments.folio_id` on the old folio. The surcharge line is an ordinary charge and can be reversed or transferred. A deposit always stays on the unit's own folio, even with a Master folio, because a payment line is never transferred.
- A payment with the SecurityDeposit purpose uses the method's own code. The system `SECURITY_DEPOSIT` code stays unused.
- A refund or payment after check-out needs the unit folio reopened first, because check-out closes it ([When to reopen](../13-folio/01-folios-and-lines.md#close-and-reopen)). At 0 it closes itself again.
- A held deposit is a credit on the unit folio like any payment, so [check-out](../18-front-desk/01-check-in-and-check-out.md#taxes-and-deposit) needs no deposit rule: the balance must reach 0, and the credit is refunded through `RefundPayment`. Check-out refunds the deposit first, then other held payments (see [Refund the credit](../18-front-desk/01-check-in-and-check-out.md#refund-the-credit)).
- The check-in form can record two payments with one method and one reference: the flagged taxes (Advance) and the security deposit (SecurityDeposit). Each has its own receipt number.
- The receipt PDF is rendered on demand from payment + folio with dompdf (see [Receipt](#receipt)). It is not stored.
- `DirectBill` is never offered here: staff use **Settle to City ledger** ([AR invoices](../15-city-ledger/02-ar-invoices.md)), which posts its own ledger line without a `payments` row. OTA collected methods are not offered yet: they come with [Booking ingestion](../21-channels/05-booking-ingestion.md).
- Card, Bank transfer and E-wallet require a reference by default ([Payment methods](../06-catalogues/04-payment-methods.md)).

## Permissions

| Permission                    | Allows                                                                                              | Default roles                       |
| ----------------------------- | --------------------------------------------------------------------------------------------------- | ----------------------------------- |
| `payment.record`              | Record a payment, download a receipt                                                                | Owner, Manager, Front desk, Finance |
| `payment.refund`              | Refund a payment to its own method, void a payment on the day it was taken, refund a folio's credit | Owner, Manager, Front desk, Finance |
| `payment.refund-other-method` | Refund to a method other than the payment's own, with a reason                                      | Owner, Manager, Finance             |

A migration syncs the permissions: it creates `payment.refund-other-method` and grants `payment.refund` to the default Front desk roles. A role an owner edited later keeps its choice. Both rows go into [Roles and permissions](../03-staff/02-roles-and-permissions.md). Front desk needs `payment.refund` to refund a deposit at check-out. The right is not tied to a screen, so Front desk can also refund from the folio tab, always to the payment's own method.

## UI (folio tab)

- **Record payment** button in the folio header, on an Open folio, with `payment.record`. A Dialog (method, purpose, amount, reference when required) shows the surcharge, its tax and the **total to collect** from the quote route before the user confirms.
- The "not started" rail entry gets Record payment, and only that: it creates the unit's folio on the first payment ([Folio page](../13-folio/05-folio-page.md)).
- A payment row shows its method and reference. Its row menu has **Refund** (`payment.refund`, a Completed payment with an amount left to refund) and **Void payment** (`payment.refund`, same business day, no refund yet). The Refund Dialog takes the amount (default: what is left), a **Refund to** field and an optional reference. Refund to lists the payment's own method first. A user with `payment.refund-other-method` also sees the other methods, and picking one shows a required Reason. Cash is greyed out with "Only a cash payment is refunded in cash." when the payment is not cash. Void payment is an AlertDialog.
- **Refund credit** in the folio header (`payment.refund`, an Open folio with a credit). A Dialog takes the amount (default: the most that can go back), and the same Refund to and Reason fields. The Dialog says the money comes from the newest payment first, security deposits last. The refusal messages are in [Refusal messages](#refusal-messages).
- A refund row is tagged "Refund".
- Payment and refund rows get no Reverse or Transfer action (see the `payment_line` rule).

### Routes

A separate `ReservationPaymentController` (the folio controller is already long). The routes sit in the reservation group with `scopeBindings()`, so a folio, unit or payment of another reservation or property returns 404. A Form Request checks the shape only.

| URL                                                | Name                                         | Action                   | Permission       |
| -------------------------------------------------- | -------------------------------------------- | ------------------------ | ---------------- |
| `POST …/{reservation}/payments/quote` (JSON)       | `reservations.payments.quote`                | `PaymentQuote`           | `payment.record` |
| `POST …/folios/{folio}/payments`                   | `reservations.folios.payments.store`         | `RecordPayment`, a folio | `payment.record` |
| `POST …/units/{unit}/payments`                     | `reservations.units.payments.store`          | `RecordPayment`, a unit  | `payment.record` |
| `POST …/folios/{folio}/payments/{payment}/refunds` | `reservations.folios.payments.refunds.store` | `RefundPayment`          | `payment.refund` |
| `POST …/folios/{folio}/payments/{payment}/void`    | `reservations.folios.payments.void`          | `VoidPayment`            | `payment.refund` |
| `POST …/folios/{folio}/refund-credit`              | `reservations.folios.refund-credit.store`    | `RefundFolioCredit`      | `payment.refund` |

- The quote needs only the method and the amount, so it hangs off the reservation. The "not started" entry uses it too.
- A payment on a "not started" entry redirects to `?folio={new folio id}`, because the `u{unit id}` entry is gone once the folio exists.
- The refund routes also take `payment_method_id` and `reason`. The Action checks `payment.refund-other-method`, so a user without it gets the `other_method_not_allowed` error on the field.
- `PaymentRefusals` maps each refusal code to a field (`payment_method_id`, `amount`, `reference`) or one message for the form, like `FolioRefusals`.
- The methods are one optional prop, `paymentOptions`, read when the Dialog opens: active methods that are not City ledger or OTA collected, with `requiresReference`. The purposes are per folio, on `FolioData` (see [Purposes by stage](#purposes-by-stage)).
- `FolioData` gets `paymentPurposes` and `defaultPaymentPurpose`, and `canRecordPayment` (an Open folio, or a "not started" entry, with at least one purpose) and `paymentUnitId` (the unit of a "not started" entry). `FolioRowData` gets `note` (the payment reference), `paymentId`, `methodType` and `refundable` (the amount left to refund), the `refund` action on an Open folio for a user with `payment.refund` when something is left, and the `void` action when the payment was posted on the business date and has no refund. `FolioData` gets `refundCredit` (the most a Refund credit can give back, or null), `refundMethods` (the methods the Refund to field offers beyond the payment's own; empty without `payment.refund-other-method`) and `canRefundOtherMethod`.

### Refusal messages

| Code                       | Field               | Message                                                                                                         |
| -------------------------- | ------------------- | --------------------------------------------------------------------------------------------------------------- |
| `refund_method_mismatch`   | `payment_method_id` | "Only a cash payment can be refunded in cash. Refund it to the original method, or to another non-cash method." |
| `other_method_not_allowed` | `payment_method_id` | "You can only refund to the original method. Ask a manager to use another one."                                 |
| `reason_required`          | `reason`            | "Add a reason for refunding to another method."                                                                 |
| `void_not_same_day`        | form                | "This payment was taken on an earlier day. Refund it instead of voiding it."                                    |
| `void_has_refund`          | form                | "This payment already has a refund, so it can't be voided."                                                     |
| `credit_not_refundable`    | `amount`            | "This folio has no credit that can be refunded."                                                                |

### Dialog defaults

| Field   | Default                                                              |
| ------- | -------------------------------------------------------------------- |
| Purpose | The first purpose of the folio's stage (see below)                   |
| Amount  | The balance when it is above 0. Empty otherwise and on "not started" |
| Method  | The first method in position order                                   |

- The quote runs again when the method or the amount changes. The Dialog shows Surcharge, Tax on surcharge and **Total to collect**, and the submit button names the total ("Record RM 105.40").
- The form sends a new UUID as the idempotency key each time the Dialog opens, so a double click records once.

### Purposes by stage

`PaymentPurposeChoices` (`app/Services/Payments/`) gives each folio its purposes, through `PaymentPurpose::forStage(FolioType, PaymentStage)`. The first one is the default. When there is one, the Dialog hides the Purpose field and sends it. When there is none, the folio has no Record payment button.

| Folio                                    | Before arrival | In-house                     | Departed   |
| ---------------------------------------- | -------------- | ---------------------------- | ---------- |
| Unit folio or entry, Room stays on it    | Advance        | Settlement, Security deposit | Settlement |
| Unit folio or entry, Room goes to Master | none           | Settlement, Security deposit | Settlement |
| Master folio                             | Advance        | Settlement                   | Settlement |
| ThirdParty folio                         | Settlement     | Settlement                   | Settlement |

- `PaymentStage` (enum): BeforeArrival, InHouse, Departed.
- A unit's stage: Confirmed is BeforeArrival, CheckedIn is InHouse, CheckedOut, Cancelled and NoShow are Departed (a cancel or no-show fee is settled).
- The Master's stage, from its live units: any CheckedIn is InHouse; all Confirmed is BeforeArrival; otherwise Departed.
- Advance and Settlement differ only on the receipt. A long-stay guest paying next week ahead gets Settlement, which lowers the balance the same way.

## Receipt

A table-based Blade PDF on A4 portrait (dompdf has no flex or grid, see [design system](../design-system/11-accessibility-performance-pdfs.md#pdfs)), as wastays prints it. It is rendered on demand and not stored. A refund has no receipt.

| URL                                               | Name                                   | Permission       |
| ------------------------------------------------- | -------------------------------------- | ---------------- |
| `GET …/folios/{folio}/payments/{payment}/receipt` | `reservations.folios.payments.receipt` | `payment.record` |

- `ReservationPaymentController::receipt` streams the PDF **inline** as `receipt-{number}.pdf`, so it opens in a new tab where staff print or save it. A refund, or a payment of another folio, reservation or property, is a 404.
- `PaymentReceipt` (service, read only) gathers what the receipt shows into one `ReceiptDocument`, so the Blade views do no lookups.
- Views: `resources/views/pdf/layout.blade.php` (shared by later PDFs) and `resources/views/pdf/receipt.blade.php`.
- The row menu of a payment row has **Download receipt** (a plain link, new tab). `FolioRowData.hasReceipt` is true for a payment (not a refund) when the user holds `payment.record`. It is a flag, not a row action, so a closed folio still offers it while its rows show no actions.

| Block         | Content                                                                                                                          |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Header        | Property name, address, phone, email, registration number and TIN (each only when set)                                           |
| Title         | "Receipt", the receipt number, the posting (business) date and the time received in the property's time zone                     |
| Received from | The folio's payer guest, or its payer company, or the booker when it has neither; the reservation reference and the folio number |
| Payment       | Method, reference, purpose                                                                                                       |
| Amounts       | Amount paid on the bill, Surcharge and Tax on surcharge (only with a fee), **Total collected**, read from the ledger             |
| Footer        | Received by (the staff member's name)                                                                                            |

Money shows as `MYR 105.40` with the property currency.

Built (receipt item): the route, `ReservationPaymentController::receipt`, `PaymentReceipt` with `ReceiptDocument`, `resources/views/pdf/layout.blade.php`, `resources/views/pdf/receipt.blade.php`, `FolioRowData.hasReceipt` and the Download receipt link in the row menu.

Decisions made at build time (receipt):

- The PDF turns on dompdf font subsetting, so a receipt is about 20 KB instead of 850 KB with the whole font embedded.
- The address shows line 1, line 2, the postal code with the city, and the country name. The state code is left out until a state name list exists.
- The receipt is in the app locale. Printing in the property's or the guest's language comes with translations.

## Build order

Three commits, in order.

1. **Backend:** `payments` table with CHECKs, `PaymentPurpose`, `PaymentStatus`, `Payment` with its factory, `PostFolioCharge` extracted from `PostCharge`, `RecordPayment`, `RefundPayment`, `PaymentQuote`, `PaymentResult` and the two permissions with a syncing migration. No UI.
2. **Folio tab:** the routes, the Dialogs, the payment rows and the Refund row action.
3. **Receipt PDF:** the layout, the route and the download link on a payment row.

Built (backend item): the `payments` table (CHECKs: amount above 0, a payment has a receipt number and a refund has none, a refund has no surcharge line, a trimmed reference) with a trigger that rejects every UPDATE and DELETE, `PaymentPurpose`, `PaymentStatus`, `Payment` and its factory, `Folio::payments()`, `FolioLine::payment()`, `PostFolioCharge`, `RecordPayment`, `RefundPayment`, `PaymentResult`, `NewPaymentData`, `PaymentQuote` with `PaymentQuoteResult`, `payment.record` and `payment.refund` (group "Payments") with a syncing migration, and the `payment_line` refusal in `ReverseLine` and `TransferLines`.

Decisions made at build time:

- `method_not_available` also covers a method whose transaction code is inactive, and a surcharge whose charge item or charge code is inactive.
- The payment line's description is the method name. A refund line uses the system Refund code and its name. A missing Refund code is a setup error (an exception), not a refusal.
- A repeated key from another reservation (or, for a refund, of another payment) is refused with `payment_not_found`.
- The quote's Data class for the Dialog comes with the folio tab item.

Built (folio tab item): `ReservationPaymentController` with `quote`, `storeForFolio`, `storeForUnit` and `refund`, the four routes, `QuotePaymentRequest`, `StorePaymentRequest`, `StoreRefundRequest`, `PaymentRefusals`, `PaymentQuoteData`, `PaymentMethodOptionData`, `PaymentFormOptionsData` and the optional prop `paymentOptions`, `FolioData.canRecordPayment` and `paymentUnitId`, `FolioRowData.note`, `paymentId` and `refundable`, the `refund` row action, `RecordPaymentDialog`, `RefundPaymentDialog`, and the reference and Refund tag in the rows table.

Decisions made at build time (folio tab):

- The quote route answers 200 with an `error` message for a refused quote (an invalid amount, a tax that cannot be calculated). A method of another property is a 404. The Dialog asks for a new quote 300 ms after the method or the amount changes, and the Record button stays disabled until a quote without an error is back.
- A recorded payment redirects to the folio page with `?folio={folio id}`, for a folio and for a "not started" entry. The toast names the amount and the receipt number. A refund goes back to the page it came from.
- The Record payment button is the primary button when the folio has no Post charge button (a "not started" entry, or a user without `folio.post-charge`), and an outline button next to Post charge otherwise.
- A payment row shows "Ref {reference}" under the method name. A refund row shows its reference the same way.

Decisions made at build time (checklist item 21):

- A unit whose Room charges go to the Master folio has no "not started" entry when all its Scheduled rows route to the Master, so the folio tab shows the Master instead. The "no purpose" case shows only for a unit folio, or for an entry that still has an unrouted row (an extra). `PaymentPurposeChoices` answers none in both.
- `ReservationResult` gained `detail` (the amount held or the invoice number), `FolioStatusResult` and `ArInvoiceResult` gained `rooms`, so a refusal message can name what blocks it.
- A mixed list reads "Room 103, Bed A1 are still in-house.": "Rooms 103, 104" is used only when every unit is a room.

## Not in this item

OTA collected payments, the Stay View Record payment button ([Stay View](../18-front-desk/03-stay-view.md)), the Cards and List money strip, online gateways with Pending payments.

## Lessons from wastays

`deposits`, `deposit_movements`, `receipts` (3 jsonb columns) and `payment_transactions` are replaced by one table.
