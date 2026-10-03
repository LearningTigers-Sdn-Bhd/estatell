# Posting charges

## Purpose

Post room charges, extras and taxes correctly and exactly once.

## Build order

The posting work is built in three items. This file is the spec for all three.

| Item                    | Built here                                                                                        | Checklist item                                               |
| ----------------------- | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| Manual postings (first) | `CreateFolio`, `PostCharge`, `ReverseLine`, `TransferLines`, a minimal `FolioRouter`, permissions | `feat(folio): post charges, reverse and transfer lines`      |
| Routing                 | The full `FolioRouter` with `folio_routing_rules` (see [Routing](03-routing.md))                  | `feat(folio): add folio routing`                             |
| Night audit postings    | `InclusionAllocator`, `PostRoomCharges`, `PostScheduledAddOns`, undated add-ons at check-in       | `feat(night-audit): post room charges and scheduled add-ons` |

## Actions

- `CreateFolio`: creates a Unit or Master folio. The number comes from `NextDocumentNumber` (`DocumentType::Folio`).
- `PostCharge`: manual charge from a charge item or an open amount.
- `ReverseLine`: posts a reversal line, including reversals of child lines.
- `TransferLines`: moves lines between folios of the same reservation.
- `PostRoomCharges`: run by the night audit for each in-house unit night (night audit item).

## Manual postings

All Actions return a result and write nothing on a refusal. Each one is one transaction. Permissions are checked by the route, not the Action, like the reservation Actions.

### `CreateFolio`

`(Reservation $reservation, ?ReservationUnit $unit): Folio`. A unit gets a Unit folio, `payer_guest_id` is the booker. No unit gives the Master folio. It is called by `PostCharge` the first time a unit receives a posting. Nothing creates folios at booking time.

### `PostCharge`

`(User $actor, ReservationUnit $unit, NewChargeData $data): PostingResult`. `NewChargeData`: `charge_item_id` (nullable), `description` and `transaction_code_id` (for an open amount), `quantity`, `unit_amount`, `idempotency_key`, optional `unit_add_on_id`.

| Check, in order                                                                                     | Refusal                       |
| --------------------------------------------------------------------------------------------------- | ----------------------------- |
| A business date exists for the property                                                             | `no_business_date`            |
| The unit is CheckedIn or CheckedOut and belongs to the property                                     | `unit_not_postable`           |
| The charge item is active (or the code is an active charge code) and belongs to the property        | `charge_not_found`            |
| Quantity is from 1 to 999                                                                           | `quantity_invalid`            |
| `unit_amount` is greater than 0 with at most two decimals                                           | `amount_invalid`              |
| An amount different from `default_amount` needs `allow_override`; an item with no default needs one | `amount_override_not_allowed` |
| The target folio is Open                                                                            | `folio_not_open`              |

- The key is `manual:{uuid}`, sent by the form, so a double click never posts twice. A key that already exists returns the existing line and writes nothing, but only when the line's folio belongs to the unit's reservation. A key that belongs to another reservation is refused (`charge_not_found`), so a key never reveals another reservation's lines. Transfers stay inside one reservation, so a legitimate replay always passes.
- It posts the charge line, then one Tax child line per `TaxCalculator` component (`parent_line_id`). `kind` is Charge for the charge and Tax for the children. `posting_date` is the business date.
- With `unit_add_on_id`, the key is `addon:{id}`, `source_type` and `source_id` name the add-on, and `unit_add_ons.folio_line_id` is set to the charge line.
- The target folio comes from `FolioRouter`.

### `PostFolioCharge`

(Planned with [payments](../14-payments/01-payments-and-refunds.md).) The line-writing core of `PostCharge`, for a given folio: it calculates tax with `TaxCalculator`, then posts the charge line and its tax children. `PostCharge` keeps its own checks (unit, charge, price, folio) and calls it. `RecordPayment` calls it for the payment surcharge, because a deposit has no CheckedIn unit for `PostCharge`.

### `ReverseLine`

`(User $actor, FolioLine $line): PostingResult`. It posts a line with the opposite amount and `reversal_of_id`, then a reversal for each child line. A tax child reversal links to its own child.

| Check                                                     | Refusal            |
| --------------------------------------------------------- | ------------------ |
| A business date exists                                    | `no_business_date` |
| The line has no parent (a child reverses with its parent) | `line_is_child`    |
| The line is not already reversed                          | `already_reversed` |
| The line is not itself a reversal                         | `line_is_reversal` |
| Its folio is Open                                         | `folio_not_open`   |

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

- A transfer moves whole charges. Their children move with them.
- The copy keeps `description`, `kind`, `quantity` and `unit_amount`. Its key is `transfer:{group}:{line_id}`.
- Source and target balances change by the same amount in opposite directions.

### `FolioRouter`

A service that returns the target folio for a charge: `targetFor(ReservationUnit, TransactionCode)`. The [routing](03-routing.md) item added the rules. With no matching rule it returns the unit's own folio, and tax children follow their parent.

## Add-on postings

- `RemoveUnitAddOn` refuses a row that has a `folio_line_id` (`add_on_posted`). The correction is `ReverseLine`.
- `unit_add_ons.folio_line_id` is a nullable foreign key to `folio_lines`. It tells posted nights from scheduled ones.

## Build status

Built (manual postings item): `CreateFolio`, `PostCharge`, `ReverseLine`, `TransferLines`, `PostingResult`, `NewChargeData`, a minimal `FolioRouter`, `unit_add_ons.folio_line_id`, the three permissions, and the `add_on_posted` refusal in `RemoveUnitAddOn`. Not built: routing rules, `InclusionAllocator`, `PostRoomCharges`, `PostScheduledAddOns`, the folio page, payments, `CloseFolio` and `ReopenFolio`. Room blocks still read `PropertyToday`.

Decisions made at build time:

- `FolioRouter::targetFor(ReservationUnit, TransactionCode)` returns null until the unit has a folio. `PostCharge` creates it only after every check passed, so a refusal writes nothing.
- The charge line holds the net amount. With an inclusive tax, `amount` is the entered amount minus the inclusive tax, and `unit_amount` stays as entered. The tax child lines add the tax back, so the folio total is the gross amount.
- Tax child keys are `{charge key}:tax:{tax_id}`. A reversal posts under `reverse:{line_id}`, also inside a transfer. A repeated `ReverseLine` is refused (`already_reversed`), not replayed.
- `TaxCalculator` refusals (`nationality_required`, `inclusive_tax_exceeds_amount`) pass through as refusal codes. The booker's nationality is used for foreign-only taxes.
- Default roles: Manager gets all three permissions, Front desk `folio.post-charge`, Finance all three.
- Logged on the reservation: `folio.charge_posted`, `folio.line_reversed`, `folio.lines_transferred`.

## Room charge pipeline (per unit night)

Built with the night audit item.

1. `InclusionAllocator` splits the night: ROOM RM250 + Breakfast RM50.
2. The room line posts at the full price. If `discount_amount` is set, a discount child line posts too.
3. `TaxCalculator` adds tax child lines.
4. `FolioRouter` chooses the target folio.
5. The idempotency key `night:{unit_night_id}` prevents double posting.

## Rules

- `ReverseLine::post()` and `TransferLines` must handle every level of children (a discount line has its own tax lines) and skip a child that already has a reversal. A second reversal would break the unique key `reverse:{line_id}`. The [folio discounts](04-folio-discounts.md) item built the recursion and the skip, with tests.
- A posting with `unit_add_on_id` takes the item, quantity and amount from the add-on row. The night audit item builds the data from the row and does not trust the caller.

- No forecast table. `unit_nights` already is the forecast, and so are the nightly `unit_add_ons` rows. The folio page reads them (see [Folio page](05-folio-page.md)).
- Surcharges from payment methods post as charge lines on the folio that receives the payment, through `PostFolioCharge` (see [Payments and refunds](../14-payments/01-payments-and-refunds.md)).
- Every posting uses `CurrentBusinessDate`. Closed dates reject postings.

## Permissions

`folio.post-charge`, `folio.reverse`, `folio.transfer`.

## Open

- Room blocks move from `PropertyToday` to `CurrentBusinessDate` "with the first folio posting" (see [Business dates](../20-night-audit/01-business-dates.md)). Decide at build time whether that switch belongs to this item or to the night audit postings.
- Closing and reopening a folio stays in a later item.

## Lessons from wastays

`folio_forecasted_charges` plus repair services (`repair_completed_nightly_charges`, `resolve_missing_nightly_charges`) existed because posting was not idempotent.
