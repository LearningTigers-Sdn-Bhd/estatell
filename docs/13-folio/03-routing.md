# Routing

## Purpose

Send charges to the right folio automatically, for example room charges to the company's master folio, diving charges to a travel agent's folio and extras to the guest's folio.

## Data model

```
folio_routing_rules    id, reservation_id,
                       reservation_unit_id (nullable, null = all units),
                       category (nullable, TransactionCategory),
                       transaction_code_id (nullable),
                       target_folio_id, source (RoutingRuleSource), timestamps
```

- `reservation_unit_id`, `category` and `transaction_code_id` say what a rule matches. Null means all.
- The table has no `property_id`: the reservation scopes it.
- `target_folio_id` is any folio of the same reservation. A CHECK cannot say this, so the Action refuses a folio of another reservation.
- Two rules cannot match the same thing: a unique index over the three selector columns treats nulls as equal (Postgres `NULLS NOT DISTINCT`, Postgres 15 and later; checked on 18.1).
- `RoutingRuleSource`: BillingMode (made by `SyncRoutingRules`), Manual (made by staff).

## Folio types

[Folios and lines](01-folios-and-lines.md) gains `FolioType::ThirdParty`: a folio with no unit and its own payer, for example a travel agent that pays only the diving charges. A reservation can have any number. `Master` stays the one folio that the billing mode manages, with at most one active (not Voided) per reservation, enforced by a partial unique index.

## Service

`FolioRouter::targetFor(ReservationUnit $unit, TransactionCode $code)`: returns the target folio for a charge. The most specific matching rule wins:

1. A rule with the code, then a rule with the category, then a rule that matches all categories.
2. At the same level, a rule for the posting unit beats a rule for all units.
3. No rule matches: the unit's own folio (null before the unit has one, and `PostCharge` creates it).

The router follows one rule and stops. A rule target is final, so there are no chains and no loops. A rule that points at the unit's own folio does nothing.

## Rules

- **Children follow their parent.** Tax and discount lines go to the folio of their charge. A rule never matches them, so a charge and its tax are never split.
- **Reversals and transfers skip the router.** `ReverseLine` posts on the line's own folio. `TransferLines` posts on the chosen target.
- **A closed target refuses the posting** (`folio_not_open`). There is never a silent fallback to the unit's folio. In the night audit this becomes an exception row that names the folio.
- **Posted lines never move on their own.** A rule change affects only later postings. A re-run of a night finds its `night:{unit_night_id}` line and posts nothing new. `TransferLines` moves old lines by hand.
- Rules are per reservation. Staff-made rules (Manual) are never touched by the billing mode.

## Billing mode rules

`SyncRoutingRules` (Action) makes the BillingMode rules match the reservation's `billing_mode`. It locks the reservation row, creates the Master folio when it does not exist (a service must not write), and removes only rules with source BillingMode.

| `billing_mode` | Rules (any unit)         | Needs                                   |
| -------------- | ------------------------ | --------------------------------------- |
| MasterPays     | Room to the Master folio | 2 or more live units, or a `company_id` |
| MasterPaysAll  | All charges to Master    | 2 or more live units, or a `company_id` |
| EachPays       | None                     |                                         |

- A reservation with a `company_id` always gets a Master folio and its rules, even with one unit, because the AR invoice is made from the master folio. Without a company the 2-unit threshold applies.
- The Master payer is the reservation's `company_id` (no foreign key until [Companies](../15-city-ledger/01-companies.md)). Without a company it has no payer.
- A live unit is one that is not Cancelled or NoShow. Cancelled and NoShow units do not count toward the 2 units.
- `SyncRoutingRules` runs after `CreateReservation`, after `AddUnit`, after `UpdateReservationGroup` changes the mode, and after a unit is cancelled or marked NoShow.
- Changing the mode affects later postings only. When a unit already holds posted room lines, the answer says so, and the lines stay on its folio.

## Examples

| Case                                  | Rules                         | Result                                     |
| ------------------------------------- | ----------------------------- | ------------------------------------------ |
| Master pays rooms, units pay extras   | Room to FM                    | Extras stay on each unit's folio           |
| Master pays all                       | All to FM                     | Unit folios stay empty                     |
| Company pays rooms, agent pays diving | Room to FM, DIVING code to FA | Everything else stays on each unit's folio |
| Each unit pays for the other (Manual) | Unit 1 to F2, unit 2 to F1    | The router supports it. No screen yet.     |

FM is the Master folio and FA a third-party folio for the travel agent.

## Build status

Built in the routing item: `folio_routing_rules` (with CHECKs on `category` and `source` and the unique index `folio_routing_rules_selector_unique` using `NULLS NOT DISTINCT`), `FolioRoutingRule` and its factory, `RoutingRuleSource`, `FolioType::ThirdParty` (type CHECK), the Master partial unique index `folios_active_master_unique`, `BillingMode::MasterPaysAll` (with the `reservations.billing_mode` CHECK), the full `FolioRouter`, `SyncRoutingRules` and the third-party form of `CreateFolio`. `CreateRoutingRule` and `RoutingRuleCheck` are the reusable place for the "target folio of another reservation" refusal. No UI and no permissions: staff screens to create third-party folios and Manual rules come later.

Decisions made at build time:

- `CreateFolio::thirdParty(Reservation, ?Guest, ?int $payerCompanyId)` takes exactly one payer. `folios` has no label column, so a third-party folio has no label: its payer names it. A label needs a schema decision first.
- `CreateRoutingRule` returns a `RoutingRuleResult` and refuses `target_folio_not_in_reservation`, `unit_not_in_reservation`, `transaction_code_not_found` and `rule_exists`. `SyncRoutingRules` uses it for BillingMode rules, so a Manual rule on the same selectors keeps its place and the sync adds nothing.
- `SyncRoutingRules` returns a `RoutingSyncResult` (`masterFolio`, `masterFolioCreated`, `rulesCreated`, `rulesRemoved`, `unitsWithPostedRoomLines`). It writes no activity log. A unit counts as holding posted room lines when its own folio has a Room charge that is not a reversal and was not reversed. The list is empty when no rule routes Room to the Master folio.
- A reservation with a `company_id` gets a Master folio in every billing mode (the AR invoice is made from it), but EachPays still has no rules. Without a company the Master folio exists only when the mode routes and the reservation has 2 or more units. Until the side item is built, Cancelled units still count toward the 2 units.
- If a BillingMode rule no longer matches (another mode, another target), the sync deletes it and creates the right one.
- `UpdateReservationGroup` now returns a `GroupUpdateResult` (it returned the `Reservation`). It runs the sync only when the billing mode changed.
- A closed Master folio still counts as the active Master (only Voided is excluded), so the sync never makes a second one.

## Known later work

- **Check-out under cross-routing:** unit 1 can be blocked by unit 2's charges on its folio. Decide in [check-out](../18-front-desk/01-check-in-and-check-out.md).
- **Closing the Master while a unit is in-house:** `CloseFolio` should refuse it.
- **Folio discounts** apply from the folio that holds the charges. A RoomOnly discount finds nothing on a unit folio when the room lines are on the master.
- **Cancellation and no-show fees** are Room charges and follow the Room rule.
- **Folio page:** Scheduled rows for master and third-party folios follow the rules ([Folio page](05-folio-page.md)).
- **Company change:** when `reservations.company_id` changes after the Master folio exists, the Master payer stays the old company. Decide in [Companies](../15-city-ledger/01-companies.md): refuse the change while the Master holds lines, or move the payer.
- **Third-party label:** a third-party folio has no label, so its payer names it. Decide before any staff screen: add a nullable `label` to `folios`, or accept the payer as the only name.
- **Side item, not built:** the live-unit rule and the cancel hook above are built by the follow-up in the [checklist](../checklist.md) (`fix(folio): count live units in routing and check the key's property`).

## Out of scope

Coverage percentages and date-ranged rules (wastays `folio_routing_rules.coverage_percentage`, `effective_from/until`).
