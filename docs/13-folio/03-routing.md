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
- **A target settled to the city ledger** (Closed, with an AR invoice that is not Void) refuses with `folio_settled_to_city_ledger` instead, in `PostCharge`, `PostRoomCharges` and `PostScheduledAddOns`. That folio cannot reopen, so the message says what to do: "The folio these charges go to is settled to the city ledger. Open a third-party folio for the company, add a routing rule for this room and this charge category to it, then post again." `FolioPostability` (`app/Services/Folio/`) picks the code, only on the refusal path. `PostUnitPolicyFee` keeps `folio_not_open`.
- **A late charge after the company invoice** goes on a second invoice: a third-party folio with the company as payer, a Manual rule for that unit to it, then **Settle to City ledger** on that folio. The Manual rule must be more specific than the BillingMode rule: under MasterPays use "this unit + Room" (a category rule for all units scores 2, so "this unit + all charges" scores only 1); under MasterPaysAll "this unit + all charges" is enough.
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
- A live unit is one that is not Cancelled or NoShow. Only live units count toward the 2 units, and only when the rules are created.
- Once the Master folio and its BillingMode rules exist, a cancel or NoShow never removes them. Only a billing mode change to EachPays does.
- A billing mode change is refused while the Master folio holds money (`master_has_payments`, the net of its payments minus their refunds above 0). Payment lines never move, so the money would sit on a folio that no longer receives the charges. See [Group bookings](../12-reservations/02-group-bookings.md). A surviving unit keeps routing to the Master, so the payer never changes silently.
- `SyncRoutingRules` runs after `CreateReservation`, after `AddUnit` and after `UpdateReservationGroup` changes the mode. It needs no hook on cancel.
- Changing the mode affects later postings only. When a unit already holds posted room lines, the answer says so, and the lines stay on its folio.
- **What stays behind is reported, not blocked.** When the sync creates a BillingMode rule (`AddUnit` going from 1 to 2 units, a company set later, a billing mode change), the result names the units whose own folio keeps posted room lines (`unitsWithPostedRoomLines`) or Advance money (`unitAdvances`, from `UnitAdvancesHeld`: Completed Advance payments minus their refunds, by unit, above 0). A payment line never moves, so that Advance can no longer meet its room charges. Blocking would stop staff adding a room, so the change saves and the toast is a warning (`RoutingChangeNotice`, `app/Services/Reservations/`): "Unit added. Room charges now go to the Master folio, but a unit's own folio holds a MYR 200.00 advance. Transfer its room charges back to it, or refund the advance." Charges can move, so staff transfer that unit's room lines from the Master folio to its own folio. A sync that creates no rule reports nothing, so the warning shows once. Check-out still needs the unit folio at 0, so nothing is lost silently.

## Bill to guest

`BillToGuest(User $actor, ReservationUnit $unit, Guest $guest): BillToGuestResult` bills one guest of a unit on a folio of their own, from the Guests tab. It is not a routing rule: earlier lines stay where they are, and later charges are posted to the folio by staff (Post charge) or by a rule.

| Guest         | Result                                                                                                                        |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Primary guest | The unit's Unit folio takes the guest as payer (`folio.payer_changed`). The folio is made when the unit has none.             |
| Other guest   | A Third party folio with the guest as payer. An Open one that already has this guest is reused (`folio.third_party_created`). |

| Refusal             | When                                                             |
| ------------------- | ---------------------------------------------------------------- |
| `guest_not_on_unit` | The guest is not on the unit, or the unit is of another property |
| `unit_not_billable` | The unit is not Confirmed, CheckedIn or CheckedOut               |
| `folio_not_open`    | The Unit folio is Closed. Reopen it or use a third-party folio   |
| `already_billed`    | The guest already pays the Unit folio                            |

- The payer does not follow the primary guest later. Changing the primary guest leaves the payer alone, and the Guests tab shows "Unit folio billed to {name}" when they differ.
- The Master folio and its payer are never touched. Tax nationality still follows the primary guest.
- The route is `reservations.units.bill-to-guest` with `can:folio.transfer`, the same permission as Third-party folio.

## Examples

| Case                                         | Rules                         | Result                                     |
| -------------------------------------------- | ----------------------------- | ------------------------------------------ |
| Master pays rooms, units pay extras          | Room to FM                    | Extras stay on each unit's folio           |
| Master pays all                              | All to FM                     | Unit folios stay empty                     |
| Company pays rooms, travel agent pays diving | Room to FM, DIVING code to FA | Everything else stays on each unit's folio |
| Each unit pays for the other (Manual)        | Unit 1 to F2, unit 2 to F1    | Made in the Routing rules Sheet            |

FM is the Master folio and FA a third-party folio for the travel agent.

## Staff screens

Staff create third-party folios and Manual rules on the [folio tab](05-folio-page.md#routing-rules-and-third-party-folios). The rules belong to the reservation, not to one folio, so both actions sit above the folio rail. The [Move charges](07-move-charges.md#scheduled-charges) page also creates Manual rules, per unit (unit + Room, or unit + Room and unit + all charges), and replaces a Manual rule with the same selectors. `FolioRouter::resolverFor()` and `pickerFor()` take an optional list of proposed rules for the preview (read only): a proposed rule replaces a saved Manual rule with the same selectors, as the Action does.

| Decision                 | Rule                                                                                                                                                                                                                                                         |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Permission               | `folio.transfer` covers both screens. Its label is "Transfer folio lines and manage routing". Routing decides who pays, the same trust as moving lines (wastays uses one permission, `manage_folio_movements`, for both). No new permission.                 |
| Third-party payer        | A guest (`GuestPicker`) or a company (`CompanySelect`). A company is any Active company of the property, and needs `company.view`. `direct_bill_enabled` is not needed to create the folio; Settle checks it.                                                |
| Rule selectors           | Unit: all units or one unit. Charges: one category from `TransactionCategory::routableCases()` (Room and the charge item categories), one charge code, or all charges. Tax, discount and payment categories are never offered: children follow their charge. |
| Rule target              | An Open folio of the reservation. `CreateRoutingRule` refuses a Closed or Voided target for a Manual rule with `target_folio_not_open`. BillingMode rules are not checked, so `SyncRoutingRules` does not change.                                            |
| Remove                   | `RemoveRoutingRule` (Action) deletes a Manual rule. It refuses a BillingMode rule with `rule_not_manual`: change the billing mode to change those. Posted lines stay where they are.                                                                         |
| Activity log             | On the reservation: third-party folio created, routing rule added, routing rule removed.                                                                                                                                                                     |
| Unused third-party folio | No delete and no void. Close it at 0.00.                                                                                                                                                                                                                     |
| Scheduled rows           | No change. The folio tab already routes Scheduled rows through the rules, so a new rule moves them at once. Posted lines never move; staff use Move charges.                                                                                                 |

`RoutingRuleRefusals` maps each refusal code to a field and a message, the way `FolioRefusals` does.

## Build status

Built in the routing item: `folio_routing_rules` (with CHECKs on `category` and `source` and the unique index `folio_routing_rules_selector_unique` using `NULLS NOT DISTINCT`), `FolioRoutingRule` and its factory, `RoutingRuleSource`, `FolioType::ThirdParty` (type CHECK), the Master partial unique index `folios_active_master_unique`, `BillingMode::MasterPaysAll` (with the `reservations.billing_mode` CHECK), the full `FolioRouter`, `SyncRoutingRules` and the third-party form of `CreateFolio`. `CreateRoutingRule` and `RoutingRuleCheck` are the reusable place for the "target folio of another reservation" refusal. No UI and no permissions at that step: see [Staff screens](#staff-screens).

Decisions made at build time:

- `CreateFolio::thirdParty(Reservation, ?Guest, ?int $payerCompanyId)` takes exactly one payer. `folios` has no label column, so a third-party folio has no label: its payer names it. The payer is the only name (decided: no label column).
- `CreateRoutingRule` returns a `RoutingRuleResult` and refuses `target_folio_not_in_reservation`, `unit_not_in_reservation`, `transaction_code_not_found` and `rule_exists`. `SyncRoutingRules` uses it for BillingMode rules, so a Manual rule on the same selectors keeps its place and the sync adds nothing.
- `SyncRoutingRules` returns a `RoutingSyncResult` (`masterFolio`, `masterFolioCreated`, `rulesCreated`, `rulesRemoved`, `unitsWithPostedRoomLines`, `unitAdvances`; the two lists only when the sync created a rule). It writes no activity log. A unit counts as holding posted room lines when its own folio has a Room charge that is not a reversal and was not reversed. The list is empty when no rule routes Room to the Master folio.
- A reservation with a `company_id` gets a Master folio in every billing mode (the AR invoice is made from it), but EachPays still has no rules. Without a company the Master folio exists only when the mode routes and the reservation has 2 or more live units (not Cancelled or NoShow). The live count applies only when rules are created: once the Master folio and its BillingMode rules exist, the sync keeps them when a unit later cancels or is marked NoShow, and removes them only for EachPays or when a rule is stale. A mode change on a kept group swaps the rule. Nothing hooks cancel or NoShow.
- If a BillingMode rule no longer matches (another mode, another target), the sync deletes it and creates the right one.
- `UpdateReservationGroup` now returns a `GroupUpdateResult` (it returned the `Reservation`). It runs the sync only when the billing mode changed.
- A closed Master folio still counts as the active Master (only Voided is excluded), so the sync never makes a second one.

Built (staff screen backend): `ReservationRoutingController` (`storeThirdPartyFolio`, `storeRule`, `destroyRule`), the three routes on `can:folio.transfer`, `StoreThirdPartyFolioRequest`, `StoreRoutingRuleRequest`, `CreateRoutingRule::manual()`, `RemoveRoutingRule`, `RoutingRuleRefusals`, `TransactionCategory::routableCases()` and the new `folio.transfer` label. The Dialog and the Sheet come in the next item.

Decisions made at build time (staff screen backend):

- `CreateRoutingRule::manual()` locks the target folio, refuses a target that is not Open, calls the same `__invoke` as the billing mode, and logs `routing_rule.created`. `__invoke` is unchanged, so `SyncRoutingRules` still writes no log.
- `CreateFolio::thirdParty()` takes an optional actor and always logs `folio.third_party_created`.
- `RemoveRoutingRule` refuses a rule that is already gone with `rule_not_found`, so a double click shows a toast instead of an error page.
- The routable categories are Room plus the charge item categories. The overview named only the charge item list, which has no Room.
- The payer guest is found in the property, not merged and not anonymized; anything else is a 404. A rule of another reservation is a 404 through `scopeBindings()` (`{routingRule}` on `Reservation::routingRules()`).
- `charges` (`category`, `code`, `all`) picks the selector; the other selector fields are ignored.

## Known later work

- **Check-out under cross-routing:** decided. The unit folio must still be 0; the check-out Dialog splits the balance by room and links to Move charges, and a folio that still receives another live unit's Scheduled charges cannot close (`folio_receives_scheduled_charges`). See [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md#rooms-that-charge-to-another-rooms-folio).
- **Closing the Master while a unit is in-house:** decided. `CloseFolio` and `SettleToCityLedger` refuse it (`units_in_house`). See [Folios and lines](01-folios-and-lines.md#close-and-reopen).
- **Folio discounts** apply from the folio that holds the charges. A RoomOnly discount finds nothing on a unit folio when the room lines are on the master.
- **Cancellation and no-show fees** are Room charges and follow the Room rule.
- **Folio page:** Scheduled rows for master and third-party folios follow the rules ([Folio page](05-folio-page.md)).
- **Company change:** decided again (checklist item 21). The Master payer follows the new company with its charges. The change is refused only while the Master holds money or has an AR invoice. See [Companies](../15-city-ledger/01-companies.md#rules).
- **An advance stranded by a later routing change:** decided. The change saves with a warning toast that names the advance (see [Billing mode rules](#billing-mode-rules)). Staff transfer the room charges back or refund the advance.
- **Third-party label:** decided. A third-party folio has no label and its payer names it. A third-party folio is one payer on one reservation, so the payer's name already identifies it. Add a nullable `label` later only when a real case needs one.

## Out of scope

Coverage percentages and date-ranged rules (wastays `folio_routing_rules.coverage_percentage`, `effective_from/until`).
