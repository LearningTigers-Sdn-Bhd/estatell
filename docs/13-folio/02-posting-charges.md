# Posting charges

## Purpose

Post room charges, extras and taxes correctly and exactly once.

## Build order

The posting work is built in three items. This file is the spec for all three.

| Item                    | Built here                                                                                                                    | Checklist item                                               |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| Manual postings (first) | `CreateFolio`, `PostCharge`, `ReverseLine`, `TransferLines`, a minimal `FolioRouter`, permissions                             | `feat(folio): post charges, reverse and transfer lines`      |
| Routing                 | The full `FolioRouter` with `folio_routing_rules` (see [Routing](03-routing.md))                                              | `feat(folio): add folio routing`                             |
| Night audit postings    | `NightChargeQuote`, `PostRoomCharges`, `PostScheduledAddOns` (`InclusionAllocator` and undated add-ons at check-in are built) | `feat(night-audit): post room charges and scheduled add-ons` |

## Actions

- `CreateFolio`: creates a Unit or Master folio. The number comes from `NextDocumentNumber` (`DocumentType::Folio`).
- `PostCharge`: manual charge from a charge item. Staff may replace the line description.
- `ReverseLine`: posts a reversal line, including reversals of child lines.
- `TransferLines`: moves lines between folios of the same reservation.
- `PostRoomCharges`: posts one unit's night for the business date. The night audit runs it for each in-house unit.
- `PostScheduledAddOns`: posts the add-ons whose `service_date` is the business date.

## Manual postings

All Actions return a result and write nothing on a refusal. Each one is one transaction. Permissions are checked by the route, not the Action, like the reservation Actions.

### `CreateFolio`

`(Reservation $reservation, ?ReservationUnit $unit): Folio`. A unit gets a Unit folio, `payer_guest_id` is the booker. No unit gives the Master folio. It is called by `PostCharge` the first time a unit receives a posting. Nothing creates folios at booking time.

### `PostCharge`

`(User $actor, ReservationUnit $unit, NewChargeData $data): PostingResult`. `NewChargeData`: `charge_item_id`, optional `description`, `quantity`, `unit_amount`, `idempotency_key`, optional `unit_add_on_id`. The folio form always sends a charge item. A one-off charge uses the closest item with its own description, so staff never pick a transaction code. The Action still accepts a null item with a `transaction_code_id` for internal callers; no screen offers it.

| Check, in order                                                                                     | Refusal                       |
| --------------------------------------------------------------------------------------------------- | ----------------------------- |
| A business date exists for the property                                                             | `no_business_date`            |
| The unit is CheckedIn or CheckedOut and belongs to the property                                     | `unit_not_postable`           |
| The charge item is active (or the code is an active charge code) and belongs to the property        | `charge_not_found`            |
| Quantity is from 1 to 999                                                                           | `quantity_invalid`            |
| `unit_amount` is greater than 0 with at most two decimals                                           | `amount_invalid`              |
| An amount different from `default_amount` needs `allow_override`; an item with no default needs one | `amount_override_not_allowed` |
| The target folio is Open (`folio_settled_to_city_ledger` when it is settled to the city ledger)     | `folio_not_open`              |

- The key is `manual:{uuid}`, sent by the form, so a double click never posts twice. A key that already exists returns the existing line and writes nothing, but only when the line's folio belongs to the unit's reservation. A key that belongs to another reservation is refused (`charge_not_found`), so a key never reveals another reservation's lines. Transfers stay inside one reservation, so a legitimate replay always passes.
- The line description is the given `description`, or else the item name (the code name for a code with no item). It is copied at posting time.
- It posts the charge line, then one Tax child line per `TaxCalculator` component (`parent_line_id`). `kind` is Charge for the charge and Tax for the children. `posting_date` is the business date.
- With `unit_add_on_id`, the key is `addon:{id}`, `source_type` and `source_id` name the add-on, and `unit_add_ons.folio_line_id` is set to the charge line.
- The target folio comes from `FolioRouter`.

### `PostFolioCharge`

(Built with [payments](../14-payments/01-payments-and-refunds.md).) The line-writing core of `PostCharge`, for a given folio: it posts the charge line and its tax children from a tax result. The caller calculates the tax with `TaxCalculator` first, so a tax refusal stops before any write. `PostCharge` keeps its own checks (unit, charge, price, folio) and calls it. `RecordPayment` calls it for the payment surcharge, because a deposit has no CheckedIn unit for `PostCharge`.

### `ReverseLine`

`(User $actor, FolioLine $line): PostingResult`. It posts a line with the opposite amount and `reversal_of_id`, then a reversal for each child line. A tax child reversal links to its own child.

| Check                                                      | Refusal            |
| ---------------------------------------------------------- | ------------------ |
| A business date exists                                     | `no_business_date` |
| The line has no parent (a child reverses with its parent)  | `line_is_child`    |
| The line is not already reversed                           | `already_reversed` |
| The line is not itself a reversal                          | `line_is_reversal` |
| No payment owns the line (correct a payment with a refund) | `payment_line`     |
| Its folio is Open                                          | `folio_not_open`   |

- The key is `reverse:{line_id}`. A discount child line reverses with its parent, so it needs no extra rule.
- A reversal of a posted add-on leaves `folio_line_id` as it is. The row stays as history.

### `TransferLines`

`(User $actor, list<FolioLine> $lines, Folio $target): PostingResult`. It reverses each line on its folio and posts a copy on the target, with children. All lines of one transfer share one `transfer_group_id`.

| Check                                                         | Refusal                 |
| ------------------------------------------------------------- | ----------------------- |
| A business date exists                                        | `no_business_date`      |
| All lines belong to one folio, and it differs from the target | `transfer_invalid`      |
| Source and target belong to the same reservation              | `different_reservation` |
| Both folios are Open                                          | `folio_not_open`        |
| No line is a child, a reversal or already reversed            | `line_not_transferable` |
| No payment owns a line                                        | `payment_line`          |

- A transfer moves whole charges. Their children move with them.
- The copy keeps `description`, `kind`, `quantity` and `unit_amount`. Its key is `transfer:{group}:{line_id}`.
- Source and target balances change by the same amount in opposite directions.

### `FolioRouter`

A service that returns the target folio for a charge: `targetFor(ReservationUnit, TransactionCode)`. The [routing](03-routing.md) item added the rules. With no matching rule it returns the unit's own folio, and tax children follow their parent.

## Add-on postings

- `RemoveUnitAddOn` refuses a row that has a `folio_line_id` (`add_on_posted`). The correction is `ReverseLine`.
- `unit_add_ons.folio_line_id` is a nullable foreign key to `folio_lines`. It tells posted nights from scheduled ones.

## Build status

Built (manual postings item): `CreateFolio`, `PostCharge`, `ReverseLine`, `TransferLines`, `PostingResult`, `NewChargeData`, a minimal `FolioRouter`, `unit_add_ons.folio_line_id`, the three permissions, and the `add_on_posted` refusal in `RemoveUnitAddOn`. Not built at that step: routing rules, `InclusionAllocator`, `PostRoomCharges` and `PostScheduledAddOns`. The folio page, payments, `CloseFolio` and `ReopenFolio` came later (see [Folio page](05-folio-page.md#build-status)).

Built (night audit postings item): `NightChargeQuote` with `NightQuote`, `QuotedCharge` and `QuotedDiscount`, `PostRoomCharges` and `PostScheduledAddOns` in `app/Actions/NightAudit/`, and a nullable actor, a `$lineAmount` and a `$discounts` list on `PostFolioCharge`. `ScheduledFolioRows` reads `NightChargeQuote`. The night audit run item calls the two Actions.

Decisions made at build time:

- `FolioRouter::targetFor(ReservationUnit, TransactionCode)` returns null until the unit has a folio. `PostCharge` creates it only after every check passed, so a refusal writes nothing.
- The charge line holds the net amount. With an inclusive tax, `amount` is the entered amount minus the inclusive tax, and `unit_amount` stays as entered. The tax child lines add the tax back, so the folio total is the gross amount.
- Tax child keys are `{charge key}:tax:{tax_id}`. A reversal posts under `reverse:{line_id}`, also inside a transfer. A repeated `ReverseLine` is refused (`already_reversed`), not replayed.
- `TaxCalculator` refusals (`nationality_required`, `inclusive_tax_exceeds_amount`) pass through as refusal codes. The nationality for foreign-only taxes is the unit's primary guest, or the booker when the unit has no primary guest (see [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md#taxes-and-deposit)).
- Default roles: Manager gets all three permissions, Front desk `folio.post-charge`, Finance all three.
- Logged on the reservation: `folio.charge_posted`, `folio.line_reversed`, `folio.lines_transferred`.

## Room charge pipeline (per unit night)

Built with the night audit postings item.

`NightChargeQuote` (`app/Services/Folio/`) works out the lines of one unit night. It reads and writes nothing. `PostRoomCharges` posts what it returns, and `ScheduledFolioRows` shows the same lines as Scheduled rows, so the preview and the posting never differ.

1. The gross is `base + extra + supplement`, or `override_amount` when set.
2. `InclusionAllocator` splits the gross: the room remainder plus one line per inclusion of the night's rate plan. The guest count is the unit's guests. A night with no inclusions keeps the whole gross as the room.
3. The discounts belong to the room only, never to an inclusion line. The stay discount (`discount_amount`) comes first, then the booking-time discount (`reservation_units.discount_id`) on what is left. An overridden night has no discount lines, because the override replaces the whole sum. Discounts that add up to more than the room remainder refuse the night (`discount_exceeds_room`).
4. `TaxCalculator` runs on the room after its discounts, and on each inclusion line, by the transaction code of each line. The room line holds the gross without the inclusive tax of the discounted amount, so the folio total is the discounted amount plus any exclusive tax. The tax lines are children of their charge. A discount line has no tax line of its own.
5. `FolioRouter` chooses the target folio for each charge code. `CreateFolio` runs when the unit has no folio yet.
6. `PostFolioCharge` writes each charge with its tax children.

| Line             | Key                              | Parent     |
| ---------------- | -------------------------------- | ---------- |
| Room             | `night:{unit_night_id}`          |            |
| Stay discount    | `night:{id}:stay-discount`       | Room       |
| Booking discount | `night:{id}:discount`            | Room       |
| Tax on a charge  | `{charge key}:tax:{tax_id}`      | Its charge |
| Inclusion        | `night:{id}:incl:{inclusion_id}` |            |

Example, RM300 with Breakfast RM50 for 2 guests and a 10% booking discount: Room 250.00, Booking discount -25.00, Breakfast 50.00, each with its tax lines.

The room line has `source_type` and `source_id` set to the unit night. Discount lines are Adjustment lines with a negative amount. The stay discount uses the property's system discount code, and the booking discount uses its own code. Each charge is routed on its own code, so an inclusion can land on another folio than the room.

### `PostRoomCharges`

`(?User $actor, ReservationUnit $unit, CarbonImmutable $date): PostingResult`. A null actor is the night audit. `PostFolioCharge` takes a nullable actor for the same reason (`folio_lines.user_id` is nullable).

| Check, in order                                                                                                                               | Refusal                                                                              |
| --------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `$date` is the current business date of the property, or before it for a [Retry](../20-night-audit/02-readiness-panel.md#retry-a-room-charge) | `no_business_date`                                                                   |
| The unit is CheckedIn, or CheckedOut for a night before the business date                                                                     | `unit_not_postable`                                                                  |
| The unit has a night on `$date`                                                                                                               | `night_not_found`                                                                    |
| The quote is calculated (tax, inclusions, discounts)                                                                                          | the quote's refusal                                                                  |
| The target folio is Open                                                                                                                      | `folio_not_open`, or `folio_settled_to_city_ledger` ([Routing](03-routing.md#rules)) |

- `$date` is the night. `posting_date` is always the current business date. The night audit passes the business date. Only `RetryRoomCharge` passes an earlier night, and then the room line description adds "night of {date}".
- A night whose `night:{id}` line exists returns that line and writes nothing. It is not a refusal, so a rerun is quiet.
- A refusal writes nothing. The audit turns it into an exception row for the unit.
- It is one transaction, with the unit row locked. It logs no activity: the lines are the record, and the audit run keeps its own step rows.
- The nationality for foreign-only taxes is `UnitNationality`, like `PostCharge`.
- The refusal codes of the quote pass through: `discount_exceeds_room`, `guests_required`, `exceeds_night_amount`, and the `TaxCalculator` refusals.

### `PostScheduledAddOns`

`(?User $actor, Property $property, CarbonImmutable $date): array<int, PostingResult>`, keyed by add-on id. It posts each add-on with `service_date` equal to `$date` and no `folio_line_id`, on a CheckedIn unit of the property. `$date` must be the current business date, otherwise it returns an empty list. The row supplies the item, quantity and amount, and the caller is never trusted. The key is `addon:{id}`, the add-on is the line's `source`, and `folio_line_id` is set to the charge line. The code must be a charge code. The item does not need to be active, because the price was copied at booking. Each add-on is its own transaction with its unit locked, so a refusal (a missing nationality, a closed folio) stops only that add-on. A refused add-on stays unposted and shows in the result for the audit. It logs no activity.

## Line sources

`source_type` and `source_id` name the unit of a line, so the folio page can show it. They are set together.

| Line                                     | Source                                                                   |
| ---------------------------------------- | ------------------------------------------------------------------------ |
| Night room line, and its inclusion lines | The unit night                                                           |
| Add-on line                              | The add-on                                                               |
| Manual charge, policy fee                | The reservation unit (an add-on posted by `PostCharge` names the add-on) |
| Reversal line                            | The source of the line it reverses                                       |
| Transfer copy                            | The source of the line it copies                                         |
| Tax and discount children                | None. They take the unit of their parent                                 |
| Payment line                             | None                                                                     |

`PostFolioCharge` takes the source as it does today. `ReverseLine` and `TransferLines` copy it. The unit shown for a posted row is read in `FolioStatement` (see [Folio page](05-folio-page.md)).

## Rules

- `ReverseLine::post()` and `TransferLines` must handle every level of children (a discount line has its own tax lines) and skip a child that already has a reversal. A second reversal would break the unique key `reverse:{line_id}`. The [folio discounts](04-folio-discounts.md) item built the recursion and the skip, with tests.
- A posting with `unit_add_on_id` takes the item, quantity and amount from the add-on row. `PostScheduledAddOns` builds the data from the row and does not trust the caller.

- No forecast table. `unit_nights` already is the forecast, and so are the nightly `unit_add_ons` rows. The folio page reads them (see [Folio page](05-folio-page.md)).
- Surcharges from payment methods post as charge lines on the folio that receives the payment, through `PostFolioCharge` (see [Payments and refunds](../14-payments/01-payments-and-refunds.md)).
- Every posting uses `CurrentBusinessDate`. Closed dates reject postings.

## Permissions

`folio.post-charge`, `folio.reverse`, `folio.transfer`.

## Open

- Room blocks move from `PropertyToday` to `CurrentBusinessDate` "with the first folio posting" (see [Business dates](../20-night-audit/01-business-dates.md)). Decided: it belongs to the audit run item, not to these postings.

## Lessons from wastays

`folio_forecasted_charges` plus repair services (`repair_completed_nightly_charges`, `resolve_missing_nightly_charges`) existed because posting was not idempotent.
