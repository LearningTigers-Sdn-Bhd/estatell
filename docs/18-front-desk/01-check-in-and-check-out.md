# Check-in and check-out

## Purpose

Move a unit through its stay.

## Actions

- `CheckInUnit`: requires an assigned room or bed for tonight (with `auto_assign_rooms` on, it first calls `AutoAssignRooms`), enforces the registration card scope, needs a known nationality when an enabled foreign-only tax exists (`nationality_required`), posts undated add-ons, sets `checked_in_at` and `UnitStatus::CheckedIn`.
- `CheckInWalkIn`: books a walk-in, checks in each unit and takes the payment, from the booking form. See [Walk-in check-in](#walk-in-check-in).
- `CheckOutUnit`: requires the unit folio balance to be 0 or transferred to the master folio (`balance_not_settled`). A held security deposit is a credit on the folio, so it must be refunded or used against charges first. It sets `checked_out_at`, `UnitStatus::CheckedOut` and marks the room Dirty.
- `ChangeUnitDates`: changes the arrival and departure of a Confirmed unit, or the departure of a CheckedIn unit. Kept nights keep their prices, plan and rooms. Removed nights are deleted. Added nights are priced for the added stretch only and try to keep the neighbouring night's room or bed.
- `ProcessEarlyDeparture`: called by `ChangeUnitDates` when the departure moves earlier. For a CheckedIn unit it applies the early departure [room revenue policy](../06-catalogues/06-room-revenue.md#policies) and removes the unused nights.
- `EarlyCheckOut`: check a CheckedIn unit out before its departure date, in one step. It runs `ProcessEarlyDeparture` with the stay day (see [Night audit pending](#night-audit-pending)) as the new departure, then `CheckOutAndRefund`, in one transaction. A refusal of either rolls both back. See [Early check-out](#early-check-out).
- `ExtendStay`: a later departure with the arrival kept. A thin wrapper over `ChangeUnitDates` (`not_an_extension` for a date that is not later). The night audit's "Extend stay" fix calls it.
- `ExtendDepartureTime`: late check-out on the departure date. Sets a later `departure_time` and posts the late check-out policy charge. Refused unless the unit is CheckedIn, on its departure date, and the new time is later and before midnight.
- `MarkNoShow`, `CancelUnit`: post the no-show or cancellation policy charge, unless staff choose **Do not charge**. Each can also delete the unit's unposted add-ons (`$removeAddOns`).
- `ReinstateUnit`: moves a Cancelled or NoShow unit back to Confirmed, with a required reason. It re-prices the unit from `reservation_unit_children` and clears the room or bed of every night.
- `ChangeUnitDates`, `ExtendStay`, `ProcessEarlyDeparture`, `MarkNoShow`, `PostUnitPolicyFee`, `RemoveUnpostedAddOns` and `RemoveUnitAddOn` take a nullable actor. A null actor is the night audit: the posted lines carry no user and the activity log has no causer.
- Each of these Actions calls `SyncReservationStatus` (module 12) after it changes a unit, so the reservation status follows its units. `ResolveMissedArrivals` in the night audit calls `MarkNoShow` for each unit its policy marks.

## Rules

- Each transition is one Action, checked by `UnitStatus::canTransitionTo()`.
- Group actions ("check in all") loop over units.
- Credit exposure is checked at check-in for company-billed reservations.
- A unit checked in after its arrival date (Keep as late arrival) also posts its missed nights through `PostMissedNights`, each with its own service date. See [Late arrival check-in](../12-reservations/12-past-and-migrated-stays.md#late-arrival-check-in).
- A night before the property's first business date is never charged (`night_before_go_live`).

## Refusals

Every Action here returns a `UnitStayResult` and writes nothing on a refusal. The unit row is locked first.

| Action         | Check, in order                                                                          | Refusal                                    |
| -------------- | ---------------------------------------------------------------------------------------- | ------------------------------------------ |
| `CheckInUnit`  | `UnitStatus::canTransitionTo()` allows CheckedIn                                         | `invalid_status`                           |
|                | A business date exists                                                                   | `no_business_date`                         |
|                | The stay day is on or after the arrival date                                             | `arrival_not_reached`                      |
|                | The stay day is before the departure date                                                | `stay_ended`                               |
|                | The unit has a room or bed on the night of the stay day                                  | `no_room_assigned`                         |
|                | The card rule: with `card_required_for_check_in`, every required guest has a signed card | `card_missing` (skipped with the override) |
|                | The nationality is known when an enabled foreign-only tax exists                         | `nationality_required`                     |
|                | A company-billed reservation passes `CreditCheck` (additional 0)                         | `credit_limit_exceeded`                    |
|                | Each undated add-on posts through `PostCharge`                                           | the `PostCharge` refusal code              |
| `CheckOutUnit` | `UnitStatus::canTransitionTo()` allows CheckedOut                                        | `invalid_status`                           |
|                | A business date exists                                                                   | `no_business_date`                         |
|                | The stay day is on or after the departure date                                           | `before_departure_date`                    |
|                | No live unit routes unposted Scheduled rows to one of the unit's own folios              | `folio_receives_scheduled_charges`         |
|                | The unit's own folios each have a balance of 0                                           | `balance_not_settled`                      |

- The route passes `$withoutCard` only to a user with `front-desk.check-in-without-card`, and `$creditOverrideReason` only to a user with `company.credit-override`. A credit override logs `credit.overridden` on the company with the action `check_in`.
- An add-on refusal rolls the whole check-in back, so the unit stays Confirmed.
- `CheckOutUnit` alone refuses a check-out before the departure date. An early check-out goes through `EarlyCheckOut`, which shortens the stay first. See [Early check-out](#early-check-out).
- Check-out marks the room Dirty: the room of the unit's last night up to the business date, or the room of its bed.
- A Master folio balance never blocks a unit check-out. Only the unit's own folios count.
- **Check-out closes the unit's own folios.** After the balance check and the status change, `CheckOutUnit` closes each Open Unit folio of the unit through `CloseFolio`, in the same transaction. A close that is refused (a posting raced in) rolls the check-out back as `balance_not_settled`. `CheckOutAndRefund` and `EarlyCheckOut` go through `CheckOutUnit`, so they close too. Master and ThirdParty folios stay Open. Closing needs no `folio.close`: it is part of check-out (`front-desk.check-out`). Each close issues the folio's invoice number once and logs `folio.closed`.

### Day use

A day use unit checks in and out on its date, and its block posts at check-in. Cancel and no-show count it as one night priced at the block. Change dates, extend, early and late check-out are not offered for it at first. See [Day use](../12-reservations/10-day-use.md#front-desk).

### Rooms that charge to another room's folio

A unit's own folio can hold another room's charges: a Manual rule such as "Room 102 · Room → Unit 101", or charges moved there with [Move charges](../13-folio/07-move-charges.md). The Master and third-party folios are not part of this, because check-out does not close them.

| Decision          | Rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Balance rule      | Unchanged: a unit checks out only when its own folios are at 0, whoever the charges belong to.                                                                                                                                                                                                                                                                                                                                                                                                            |
| Whose charges     | The check-out preview splits the unit folio balance by room, from the line source (a child takes its parent's). When another room's charges are in it, the Dialog lists each room with its amount and says "This folio pays for another room. Collect it all here, or move Room 102's charges first." with **Move charges**, which opens the Move charges page with those lines ticked (`?lines[]=`). With only the room's own charges nothing new shows.                                                 |
| Scheduled charges | Check-out is refused while a live unit (Confirmed or CheckedIn) still routes unposted Scheduled rows to one of the unit's own folios (`folio_receives_scheduled_charges`). Every unposted row counts, whatever its date: tonight's night is posted by tonight's audit, and an older night that failed is retried by the next one. Otherwise the folio closes and the next night audit fails those nights with `folio_not_open`. The check runs before the balance check, so the message is the right one. |

- **Message:** "Room 102 (15–16 Oct) still charges its scheduled nights to this folio. Send them to another folio first." with **Move charges**, which opens the Move charges page with nothing ticked; the room's scheduled choice on the From side changes the rule. Several rooms are listed with their dates: "Room 102 (15–16 Oct) and Room 103 (15 Oct) still charge their scheduled nights to this folio."
- `IncomingScheduledCharges` (service, `app/Services/Folio/`) lists the live units and their dates whose unposted Scheduled rows route to a folio, through `FolioRouter` and the same rows as the folio tab. `CheckOutUnit` and `CloseFolio` both use it.
- The `balance_not_settled` message names other rooms when their charges are in the balance: "The unit folio is not settled. It holds MYR 540.00 for Room 102. Collect it, or move those charges first."
- **A checked-out unit's folio closes itself at 0.** After a posting, payment, refund, reversal or transfer leaves an Open Unit folio of a CheckedOut unit at 0, the Action closes it in the same transaction (`CloseFolio`). So staff reopen to fix something and never have to close again. A folio left above 0 stays Open, and the night audit lists it ("Folio still open": the guest left owing money).
- Logged on the reservation: `reservation.unit_checked_in` and `reservation.unit_checked_out`.

### Early check-out

`EarlyCheckOut` is a wrapper over `ProcessEarlyDeparture` and `CheckOutAndRefund`. It has no rules of its own beyond the first two checks.

| Check, in order                                                                                           | Refusal                                         |
| --------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| The unit is CheckedIn (the unit row is locked first)                                                      | `invalid_status`                                |
| A business date exists and it is before the departure date                                                | `no_business_date`, `not_early`                 |
| The new departure (the stay day) is after the arrival date                                                | `arrived_today`                                 |
| **Guest left yesterday** only while the audit is pending and the night of the business date is not posted | `left_yesterday_unavailable`                    |
| The night audit is not Overdue (calendar date 2 or more days after business date)                         | `night_audit_pending`                           |
| The checks of `ProcessEarlyDeparture` (reason, policy row, `fee_changed`, charge)                         | its refusals                                    |
| The refund and the checks of `CheckOutUnit`                                                               | `refund_exceeds_payment`, `balance_not_settled` |

- **One transaction.** The fee, the deleted nights, the payment, the refund and the check-out commit together. A refusal at any step writes nothing.
- **New departure** is the stay day: the business date, or the property's calendar date while the night audit is pending. The form never sends it, except the **Guest actually left yesterday** choice (see [Night audit pending](#night-audit-pending)).
- **Same-day stay.** A unit that arrived on the business date has no night to keep, so `departure_date > arrival_date` cannot hold. It is refused with `arrived_today`. `EarlyCheckOutRule` (`app/Services/FrontDesk/`) holds this rule and the unit menu, the group check-out page, the preview and the Action all ask it, so none of them offers what another refuses. The Dialog's preview carries the message and the group page shows it as the row note, without **Check out early…**. The guest checks out on the departure date, or after Undo check-in once it is built (see [Cancel, void and undo](#cancel-void-and-undo)).
- **Departure today or later.** `not_early` is refused. Normal check-out runs through `CheckOutAndRefund`.
- **Fee:** read by `PolicyChargeCalculator` with the new departure, and posted with the key `early_departure:{unit}:{n}`. **Do not charge** needs a reason and `front-desk.waive-fees`. `$expectedFee` from the form guards against a policy change (`fee_changed`).
- **Balance:** the fee joins the unit folio, so the balance to settle includes it. Posted add-ons on removed nights stay and can leave a balance. The form shows the balance before submit, and settles it as the check-out Dialog does: Collect balance when the guest owes, a refund when the folio holds a credit ([Refund the credit](#refund-the-credit)).
- **Money for the unused nights.** The night audit posts one night at a time, so the unused nights were never charged: they are Scheduled and are deleted. A payment line never changes, so money paid for them stays on the folio as a **credit**. The fee is the only part of that credit the hotel keeps. The rest goes back to the guest at check-out. See [Early departure examples](#early-departure-examples).
- **Night audit pending:** the new departure is the calendar date and the night of the business date is posted first. See [Night audit pending](#night-audit-pending). Only an Overdue audit still refuses (`night_audit_pending`).

#### Early departure examples

3 nights at MYR 100.00, booked 19 to 22 Oct. The guest paid MYR 300.00 as an Advance and a MYR 100.00 deposit, and leaves on 20 Oct after 1 night. Taxes are left out.

| Policy                    | Night posted | Fee    | Paid   | Balance | At check-out                                  |
| ------------------------- | ------------ | ------ | ------ | ------- | --------------------------------------------- |
| Free                      | 100.00       | 0.00   | 400.00 | −300.00 | Refund 300.00: deposit 100.00, Advance 200.00 |
| Fixed amount 50.00        | 100.00       | 50.00  | 400.00 | −250.00 | Refund 250.00: deposit 100.00, Advance 150.00 |
| 100% of unused nights     | 100.00       | 200.00 | 400.00 | −100.00 | Refund 100.00: the deposit                    |
| Free, nothing paid before | 100.00       | 0.00   | 100.00 | 0.00    | Use the deposit toward the bill, or refund it |
| Free, no deposit, unpaid  | 100.00       | 0.00   | 0.00   | 100.00  | Collect 100.00                                |
| **Do not charge**         | 100.00       | 0.00   | 400.00 | −300.00 | Refund 300.00, as Free                        |

Built: the Advance is refunded with the deposit, so the first two rows check out in one step. [Refund the credit](#refund-the-credit) describes how.

| Edge case                                                | What happens                                                                                                         |
| -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| The night audit has not run                              | The night of the business date is posted, then the guest checks out. See [Night audit pending](#night-audit-pending) |
| Arrived today, leaves today                              | Refused (`arrived_today`). Undo check-in, then cancel. A later topic                                                 |
| The card surcharge of the prepayment                     | Not refunded: it is a charge. Staff reverse it by hand when it must go too                                           |
| A posted add-on for a removed day (a transfer, a tour)   | Stays. Staff reverse it when the guest did not use it                                                                |
| An unposted nightly add-on (breakfast) on removed nights | Deleted with the nights                                                                                              |
| Room charges go to the Master folio                      | The unit folio holds no prepayment. A credit on the Master settles when the group settles                            |
| An OTA collected the money                               | Not built. The OTA refunds the guest, not the desk ([Booking ingestion](../21-channels/05-booking-ingestion.md))     |
| Keep part of the deposit for damages                     | The Dialog posts the charge ([Keep the deposit for damages](#keep-the-deposit-for-damages))                          |
| A charge is posted while the Dialog is open              | The refund no longer settles the folio: `balance_not_settled`, nothing is written                                    |
| Undo check-out after an early check-out                  | Payments and refunds stay. The nights and the fee do not come back: extend the stay, reverse the fee                 |

- **Permissions:** `front-desk.check-out` and `reservations.manage`, both checked by the route. The refund needs `payment.refund`, as in the check-out Dialog.
- Logged on the reservation: `reservation.unit_dates_changed` and `reservation.unit_checked_out`, each as today.

### Night audit pending

Staff can check guests in and out before the night audit runs. They never need to know what the audit is. The system works out what happened to the pending night and says so in one line.

Example: business date **20 Oct**, the property's calendar date **21 Oct**, audit not run yet.

- **When it applies.** The property's calendar date (`PropertyToday`, its time zone) is 1 day after the business date: the Audit pending state of the [business date indicator](../design-system/09-app-shell.md#business-date-and-system-date). An Overdue audit (2 or more days behind) keeps the old rules, and early check-out refuses `night_audit_pending` there.
- **When it applies, running audit.** A date that is Auditing counts as pending too: the calendar date is still 1 day after it. Nothing is refused. The audit locks each unit row and every key is idempotent, so a check-out before the room charge step is skipped by it, and one after it replays the posted night. The strip says "Night audit running" instead of "not run yet".
- **Stay day.** `StayDay` (`app/Services/FrontDesk/`) holds the rule. For check-out, early check-out, late check-out and the menu it is the calendar date while the audit is pending, otherwise the business date. For check-in it is the calendar date only when the unit arrives on the calendar date, otherwise the business date, so a guest booked 20 → 21 Oct who arrives after midnight still checks in for the night of 20 Oct. Every posting still uses the business date.
- **Not affected.** Day use units keep the business date. Past and migrated stays (`$asOf`, `$skipBalanceCheck`) post no pending night and keep their own day. The wastays replay alone passes `sameDayLeaver: true` to `CheckOutUnit`: a one-night stay checks out on its arrival business date, and `PostPendingNight` posts its night first (see [Grand Eastern](../migration-plan/grand-eastern-hotel.md#day-loop)). The dashboard's arrivals and departures still show the business date: a later topic.
- **The pending night.** A guest still checked in on 21 Oct slept the night of 20 Oct. Before the check-out, the same transaction posts that night through `PostRoomCharges` and its add-ons dated 20 Oct through `PostScheduledAddOns::post()`. The keys are the audit's (`night:{unit_night_id}`, `addon:{id}`), so the audit finds the lines and writes nothing. The night must post **before** the status changes: `PostRoomCharges` refuses a CheckedOut unit for the night of the business date, and the audit never posts add-ons of a CheckedOut unit. A refusal of either posting rolls the check-out back.
- **`PostPendingNight`** (Action) does this posting. It does nothing unless the audit is pending, the unit is CheckedIn and it has a night on the business date, so a unit that arrived on the calendar date or departs on the business date is skipped, not refused. It runs at the start of `CheckOutAndRefund` and `EarlyCheckOut`, before the payment and the refund, so the balance they settle includes the night. `CheckOutUnit` calls it too, for `CheckOutGroup` and other direct callers. A second call writes nothing. The preview reads the same lines through `NightChargeQuote`, so the Dialog's balance includes them.

| Case                                           | What happens                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Check-out, departure 21 Oct                    | Post the 20 Oct night and add-ons, then check out                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Early check-out, departure 23 Oct              | New departure 21 Oct. Post the 20 Oct night and add-ons, remove 21 and 22 Oct, then check out                                                                                                                                                                                                                                                                                                                                                                                             |
| Early check-out, **Guest left yesterday**      | New departure 20 Oct. Nothing posts for 20 Oct. Reason required. The early departure fee is read with the 20 Oct departure; Do not charge stays available. A unit that arrived on 20 Oct is refused with `arrived_today`                                                                                                                                                                                                                                                                  |
| Check-out, departure 21 Oct, guest left 20 Oct | The Dialog shows two cards: **They left yesterday** (the early check-out path with Guest left yesterday: departure 20 Oct, no night posted, reason required) and **They stayed last night** (the plain check-out above, selected by default). The cards show only while the audit is pending, the unit is CheckedIn, its night of the business date is not posted, it did not arrive on the business date and it does not depart on the business date (that guest gets the Overdue cards) |
| Late check-out, departure 21 Oct               | Offered: the departure is the stay day                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| Overdue guest, departure 20 Oct                | The Dialog asks (below)                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Check-in, arrival 21 Oct                       | Allowed. The room is the one on the night of 21 Oct. Tonight's audit posts 21 Oct                                                                                                                                                                                                                                                                                                                                                                                                         |
| Check-in, arrival 20 Oct (arrives after 0:00)  | As today: the check-in day is the business date, so the room is the one on the night of 20 Oct                                                                                                                                                                                                                                                                                                                                                                                            |

**Left yesterday cards.** `UnitCheckOutPreviewData::$leftYesterdayChoice` is true while the audit is pending and `EarlyCheckOutRule::refusal(..., leftYesterday: true)` is null or `arrived_today`. For `arrived_today` the **They left yesterday** card is shown disabled, and `leftYesterdayBlocked` carries the reason ("Arrived on 21 Oct, so there is no night to remove. If they checked in by mistake, use Undo check-in."). The card is hidden when the night is already posted, the unit departs on the business date or the audit is not pending. The early check-out Dialog uses the same two cards in place of the old text link, so the choice looks the same whether the guest departs on the stay day or later. Choosing **They left yesterday** reloads the preview with `left_yesterday`, which adds the `early` block, and the form posts to `reservations.units.early-check-out`.

**Overdue guest.** A guest booked to 20 Oct who is still checked in may have stayed, or staff forgot to check them out. Only staff know, so the check-out Dialog asks:

```
This guest was due to leave yesterday (20 Oct). What happened?

┌─────────────────────────┐  ┌─────────────────────────┐
│ They left yesterday     │  │ They stayed last night  │
│ Check out on 20 Oct.    │  │ Add 20 Oct (RM 180),    │
│ No extra night.         │  │ then check out today.   │
└─────────────────────────┘  └─────────────────────────┘
```

**They stayed** runs `ExtendStay` to 21 Oct, posts the night of 20 Oct and checks out, in one transaction (`CheckOutOverdueStay`, request field `stayed_last_night`). Restrictions are passed with a fixed reason, "Guest stayed the night", because the guest already slept there. `not_available` is still refused: "Room 204 is booked by another guest for 20 Oct. Move one of them first." **They left** is the normal check-out on the business date.

**Group check-out.** The [group check-out page](05-group-check-out.md) follows the same rules: a row that departs on the stay day is ready, and `CheckOutGroup` posts its pending night before it moves charges or settles. The `night_audit_pending` row note stays only for an Overdue audit. A room that was due to leave on the business date stays ready as "They left yesterday", with the note "Due to leave yesterday (20 Oct). If they stayed last night, check this room out on its own." The page has no "They stayed" choice.

**Dialogs.** Check in, Check out and Check out early show a `warning` strip at the top of the body while the audit is pending: "Hotel day 20 Oct · Today 21 Oct. Night audit not run yet." It uses the sidebar's word "Night audit". An ⓘ line under the stay says what it means for this guest: "Last night (20 Oct) is added to the bill now. The night audit will skip it." or "That's fine. Tonight's night audit charges 21 Oct." The bill marks the posted lines **(added now)**. The preview sends the strip, the line and the lines to post, so the browser has no date rules of its own.

| Checked in the code (2026-10-22)         | Result                                                                                                                      |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| The audit skips a posted night           | Yes. `PostRoomCharges` replays an existing `night:` line. `PostRoomChargesStep` only picks CheckedIn units                  |
| Missed arrivals and an early check-in    | Not touched. `ResolveMissedArrivals` only reads Confirmed units. No step reads a unit with no night on the business date    |
| Add-ons dated the departure day (21 Oct) | Not posted at check-out, as today                                                                                           |
| Departures report                        | Right: it filters on `departure_date`                                                                                       |
| Occupancy and ADR                        | Must change: `StatisticsCalculator::sleptNights()` counts CheckedIn units only. It must also count CheckedOut units' nights |
| Arrival and departure counts             | Must change: the check-in and check-out log entries carry the stay day as `business_date`, so 21 Oct counts on 21 Oct       |

- **Statistics.** A CheckedOut unit keeps only the nights it slept (an early check-out deletes the rest), so counting its nights as sold changes nothing that works today. See [Statistics](../20-night-audit/05-statistics.md).
- **Undo check-out** before the audit sets the unit back to CheckedIn. The night stays posted and the audit skips it.
- **Undo check-in, known gap.** A guest checked in at 1:00 on 21 Oct cannot be undone after the audit runs at 3:00, although no night is posted: `UndoCheckInRule` compares `checked_in_at` with `opened_at` of 21 Oct. Accepted for now.
- **Replaces** the plain `night_audit_pending` refusal of early check-out (`d88ba864`) for the Audit pending state.

### Cancel, no-show and reinstate

| Action          | Check, in order                                                                  | Refusal                                           |
| --------------- | -------------------------------------------------------------------------------- | ------------------------------------------------- |
| `CancelUnit`    | `UnitStatus::canTransitionTo()` allows Cancelled                                 | `invalid_status`                                  |
| `MarkNoShow`    | `UnitStatus::canTransitionTo()` allows NoShow                                    | `invalid_status`                                  |
| both            | A business date exists                                                           | `no_business_date`                                |
| `MarkNoShow`    | The business date is on or after the arrival date                                | `arrival_not_reached`                             |
| both            | Do not charge comes with a reason                                                | `reason_required`                                 |
|                 | The property has the cancellation or no-show policy row                          | `policy_not_found`                                |
|                 | When a fee posts and `$expectedFee` is given, it equals the fee now              | `fee_changed`                                     |
|                 | The policy's charge code is active, its folio is open and its tax calculates     | `charge_not_found`, `folio_not_open`, tax refusal |
| `ReinstateUnit` | `UnitStatus::canTransitionTo()` allows Confirmed                                 | `invalid_status`                                  |
|                 | The reason is not blank                                                          | `reason_required`                                 |
|                 | A business date exists                                                           | `no_business_date`                                |
|                 | The business date is before the departure date                                   | `stay_ended`                                      |
|                 | The room type has a free unit on every night (the room type row is locked first) | `not_available`                                   |
|                 | `RateQuoteService` can price the stay                                            | the quote refusal (for example `rate_missing`)    |

- The fee comes from `PolicyChargeCalculator` on the business date. `PostUnitPolicyFee` posts it with `PostFolioCharge`, because `PostCharge` refuses a unit that is not CheckedIn or CheckedOut. The key is `cancel:{unit}:{n}` or `no_show:{unit}:{n}`, where n counts the unit's earlier fees of that kind, so a unit cancelled again after a reinstate is charged again. A fee of 0 posts nothing.
- **Do not charge** is a route decision: the route passes `$doNotCharge` only to a user with `front-desk.waive-fees`. The Action needs the reason and logs it with the fee it did not post.
- `$removeAddOns` runs `RemoveUnpostedAddOns` while the unit is still Confirmed. It calls `RemoveUnitAddOn` for each add-on with no folio line, so each removal is logged. A posted add-on stays. With the option off, add-ons stay on the unit and come back with it on reinstate. Removed add-ons do not come back.
- Cancel and no-show keep the night rows and their prices as history and clear `room_id` and `bed_id` on every night (`ReleaseUnitRooms`), so the rooms can be sold again. The released room and bed ids are in the log. A Cancelled or NoShow unit does not hold inventory (`UnitStatus::holdsInventory()`). Reinstate has nothing to clear (it clears again as a safeguard), so staff pick a room with `AssignRoom` or `AssignBed`. This follows wastays, where a reinstated booking picks from the rooms free now.
- Reinstate keeps a stay override: a night with an `override_amount` keeps it as its amount. It never touches a posted fee.
- Logged on the reservation: `reservation.unit_cancelled`, `reservation.unit_no_show` and `reservation.unit_reinstated`.

### Cancel reservation

One Dialog cancels several units, so a group is not cancelled one Dialog at a time and never ends half cancelled.

```
┌ Cancel reservation · WA1-0142 · Acme Sdn Bhd ────────────────────────┐
│ ⓘ Acme Sdn Bhd requested cancellation on 10 Oct.                     │
│ ☑ Room 101 · Deluxe King · 14–17 Oct · Tan Wei Ming    Fee 280.00    │
│ ☑ Room 102 · Deluxe King · 14–17 Oct · Lee Mei Ling    Fee 280.00    │
│ ☐ Room 110 · Checked in: use Check out or Early check-out            │
│ Cancellation policy: Free until 2 days before arrival.               │
│ Fees            9 rooms         MYR 2,520.00 + tax  = 2,721.60       │
│ ☐ Do not charge     Reason [ …required with Do not charge… ]         │
│ ☐ Also remove add-ons                                                │
│ Paid on this reservation        MYR 2,000.00                         │
│ After the fees, Acme owes       MYR 721.60                           │
│                             [Keep reservation] [Cancel 9 rooms]      │
└──────────────────────────────────────────────────────────────────────┘
```

| Rule           | Detail                                                                                                                                                                                                                                         |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Entry          | **⋯ More › Cancel reservation** on the reservation page, with `reservations.manage`, when at least one unit is Confirmed                                                                                                                       |
| Rows           | Every unit. A Confirmed unit is ticked by default. Any other unit is dimmed with the reason; a CheckedIn unit says "Checked in: use Check out or Early check-out."                                                                             |
| Untick         | Cancel only some rooms. The button reads "Cancel 3 rooms"                                                                                                                                                                                      |
| Fee per row    | `PolicyChargeCalculator` on the business date, the same figure as Cancel unit; "No charge" inside the free window                                                                                                                              |
| Do not charge  | One switch and one reason for every ticked unit, with `front-desk.waive-fees`. Waiving one room only uses Cancel unit                                                                                                                          |
| Add-ons        | One **Also remove add-ons** switch, the same meaning as Cancel unit                                                                                                                                                                            |
| Money line     | Paid on the reservation (net, every folio that is not Voided) against the fees with tax: "owes" or "to refund". Read only; a refund is made on the folio tab, and the night audit's **Money held on a closed booking** warning still covers it |
| Portal request | When `cancellation_requested_at` is set, a banner names the company and the date. Cancelling resolves the request, and the log says so                                                                                                         |
| Preview        | `UnitActionPreview::cancelAll()` gives the rows, fees and money line, from `POST …/{reservation}/cancel/preview`                                                                                                                               |

`CancelUnits(User $actor, Reservation $reservation, list<ReservationUnit> $units, bool $doNotCharge, ?string $reason, bool $removeAddOns, array $expectedFees): CancelUnitsResult` (`app/Actions/FrontDesk/`):

- Calls `CancelUnit` for each ticked unit **in one transaction, all or nothing**. A refusal cancels nothing and names the room ("Room 106: the folio is closed."). A failed check-in leaves the others in, but a half-cancelled group leaves rooms held and fees half posted, so cancel does not follow Check in all here.
- `$expectedFees` holds one fee per unit from the preview; a changed fee refuses (`fee_changed`) with the room.
- Each unit keeps its own `reservation.unit_cancelled` entry, and one `reservation.cancelled` entry holds the count, the total fee and the reason.
- `SyncReservationStatus` runs once at the end.
- Route `POST …/{reservation}/cancel` (`reservations.cancel`), `reservations.manage`; Do not charge is passed only with `front-desk.waive-fees`.
- No **No-show all**: the night audit's missed arrivals step marks no-shows.

### Cancel, void and undo

|              | Cancel                                   | Void (later)                                                                                               | Undo check-in                                  | Undo check-out                                                               |
| ------------ | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------- | ---------------------------------------------- | ---------------------------------------------------------------------------- |
| For          | A real booking called off before arrival | A booking that should never have existed: duplicate, test, wrong property                                  | Checked in by mistake                          | Checked out by mistake, or the guest stays on                                |
| Allowed when | The unit is Confirmed                    | Nothing posted is left (all reversed) and no money is left (refunded)                                      | Same business date, before any night is posted | Checked out on the current business date, and the room is still free tonight |
| Fee          | Policy may charge                        | Never                                                                                                      | None                                           | None                                                                         |
| Reports      | Counts as a cancellation                 | Left out                                                                                                   | –                                              | –                                                                            |
| Result       | Unit Cancelled; Reinstate undoes it      | `voided_at` and a reason on the reservation; its units Cancelled with no fee; hidden from the default list | Unit back to Confirmed, room assignment kept   | Unit back to CheckedIn, its folios reopened                                  |

- A CheckedIn unit cannot be cancelled: the guest has used the room. Early check-out or check-out is the action.
- **Void** uses a `voided_at` mark on a Cancelled reservation instead of a new status, so code that reads "closed" statuses does not need a second case (the wastays bug where a voided booking read as Paid).
- **Undo check-out** keeps every payment and refund made at check-out (ledger lines), reopens the unit folios with their invoice number through `ReopenFolio`, and leaves the room Dirty. It is refused after the night audit (`undo_too_late`), when another unit has the room tonight (`room_taken`), when a unit folio was settled to the city ledger since, and without a reason. After an **early** check-out it does not restore the removed nights or reverse the fee; the Dialog says to extend the stay and reverse the fee if it no longer applies. A guest staying on is Undo check-out, then Extend stay.
- **Undo check-in** sets the unit back to Confirmed and clears `checked_in_at`. It is allowed only when the unit checked in during the open business date (`checked_in_at` is at or after `business_dates.opened_at`) and no `night:` or `dayuse:` line is posted for it (`night_posted`), so a day use unit never qualifies. It keeps what check-in posted: payments, collected taxes and deposit, and add-ons posted at check-in stay on the folio, and the Dialog lists them so staff can reverse them from the folio. The room or bed assignment stays; Cancel releases it later. Signed registration cards stay.
- **Undo check-out** uses the same business date rule on `checked_out_at` (`undo_too_late` after the night audit). `UndoCheckOutRule` also refuses `room_taken` when another unit has a night, or a day use block that is not released, on the room or bed the guest used last, and `folio_has_ar_invoice` when a folio to reopen was settled to the city ledger. It reopens only the unit folios that check-out closed (`closed_at` at or after `checked_out_at`), keeps every payment and refund, and leaves the room Dirty. The Dialog lists the folios that reopen with their invoice numbers.
- **Permissions.** Undo check-in needs `front-desk.undo-check-in` and Undo check-out `front-desk.undo-check-out`, both Owner and Manager only. Both need a reason and log `reservation.unit_check_in_undone` or `reservation.unit_check_out_undone`. The unit **⋯** menu shows the item only while the rules allow it.

### Change dates, extend and late check-out

| Action                  | Check, in order                                                                          | Refusal                                              |
| ----------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| `ChangeUnitDates`       | The unit is Confirmed or CheckedIn                                                       | `invalid_status`                                     |
|                         | A business date exists                                                                   | `no_business_date`                                   |
|                         | The departure is after the arrival                                                       | `invalid_stay`                                       |
|                         | At least one date changes                                                                | `dates_unchanged`                                    |
|                         | An in-house unit keeps its arrival and does not depart before the business date          | `arrival_locked`, `departure_in_past`                |
|                         | A Confirmed unit's new arrival is not before the business date                           | `arrival_in_past`                                    |
|                         | The plan is not a Package plan                                                           | `package_plan`                                       |
|                         | The unit is not a day use (its date and hours do not change)                             | `day_use_unit`                                       |
|                         | New nights pass `RestrictionCheck` (an override reason lets them through)                | `restricted`                                         |
|                         | Every new night has a free unit on the room type (the room type row is locked first)     | `not_available`                                      |
|                         | `RateQuoteService` can price the new nights                                              | the quote refusal (for example `rate_missing`)       |
|                         | A shorter departure: the checks of `ProcessEarlyDeparture` below                         | its refusals                                         |
| `ProcessEarlyDeparture` | The new departure is earlier                                                             | `not_shorter`                                        |
|                         | Do not charge comes with a reason                                                        | `reason_required`                                    |
|                         | The property has the early departure policy row                                          | `policy_not_found`                                   |
|                         | When a fee posts and `$expectedFee` is given, it equals the fee now                      | `fee_changed`                                        |
|                         | The charge code is active, its folio is open and its tax calculates                      | `charge_not_found`, `folio_not_open`, tax refusal    |
| `ExtendStay`            | The new departure is later than the current one, then the checks of `ChangeUnitDates`    | `not_an_extension`                                   |
| `ExtendDepartureTime`   | The unit is CheckedIn                                                                    | `invalid_status`                                     |
|                         | A business date exists and it is the departure date                                      | `no_business_date`, `not_departure_date`             |
|                         | The new time (`H:i`) is later than the current `departure_time`                          | `time_not_later`                                     |
|                         | No live day use in the unit's room on that date starts before the new time               | `room_not_free`                                      |
|                         | Do not charge comes with a reason, the policy row exists, the fee matches, the fee posts | `reason_required`, `policy_not_found`, `fee_changed` |

- `ChangeUnitDates` and `ProcessEarlyDeparture` return a `StayDatesResult` (the unit, the fee, whether it was charged, the unit total before and after, and `unassignedNights`). `ExtendDepartureTime` returns a `UnitStayResult`.
- **Prices:** nights that stay never change: a rate change does not touch an existing booking. Added nights are quoted for the added stretch only, so a long-stay discount does not reach the old nights. A stay override keeps its share on the old nights.
- **Rooms:** an added night takes the room or bed of the nearest kept night when it is free on those nights. If not, it stays unassigned and counts in `unassignedNights`. Staff assign with `AssignRoom` or `AssignBed`.
- **Add-ons:** `AddNightlyAddOnRows` gives each nightly add-on (PerNight, PerGuestNight) rows for the new nights on or after the business date. A shorter stay deletes the unposted rows of the removed nights. Other add-ons and posted add-ons stay.
- **Early departure fee:** `PolicyChargeCalculator` reads it with the new departure while the unit still holds its booked nights. It posts with `PostUnitPolicyFee` and the key `early_departure:{unit}:{n}`. Only a CheckedIn unit with a shorter departure and the arrival unchanged is an early departure. A Confirmed unit that has not arrived is a date change with no fee: the guest has not left early, and the cancellation policy (first night or whole stay) does not price removed nights. Staff post a manual charge if the hotel wants one. A change that also moves the arrival is a new booking of the unit and has no fee.
- **Restrictions:** the route passes `$restrictionOverrideReason` only to a user with `rates.override-restrictions`. Without a reason, a restriction is refused.
- **Late check-out:** the fee is the late check-out policy (`OneCharge`) with the key `late_checkout:{unit}:{n}`. A second extension on the same day posts the fee again, and staff can choose Do not charge.
- Permissions: `ChangeUnitDates` and `ExtendStay` need `reservations.manage`. `ExtendDepartureTime` needs `front-desk.check-out`. Do not charge needs `front-desk.waive-fees`.
- Logged on the reservation: `reservation.unit_dates_changed` (old and new dates, totals, fee, reasons) and `reservation.unit_departure_time_extended`.

## Taxes and deposit

- **Nationality** for a foreign-only tax is the unit's primary guest, or the booker when the unit has no primary guest. Check-in shows a Nationality field when it is missing and saves it to the guest profile.
- **Taxes stay on the room revenue line.** Check-in posts no tax. Each night's tax is a child line of the room line (see [Posting charges](../13-folio/02-posting-charges.md)). A tax with **Collect at check-in** ([Taxes](../06-catalogues/03-taxes.md)) only changes what the guest is asked to pay at check-in.
- **Security deposit** is a payment with `PaymentPurpose::SecurityDeposit` on the unit's own folio. The default amount is `property_settings.security_deposit_amount`.
- **Held deposit** is a credit, so the plain zero-balance rule at check-out covers it. The check-out form uses the credit toward the bill, or refunds it with `RefundPayment`. To keep part of it for damages, staff post a charge on the folio first (the deposit offsets it), then refund the rest. The check-out form now posts the damage charge itself ([Keep the deposit for damages](#keep-the-deposit-for-damages)).

## Permissions

`front-desk.check-in`, `front-desk.check-out`, `front-desk.check-in-without-card`, `front-desk.waive-fees`, `front-desk.undo-check-in`, `front-desk.undo-check-out` (Owner and Manager). The cancel, no-show and reinstate Actions need `reservations.manage`, checked by the route.

## Build order

One checklist item per group, in this order. The Actions come first and the reservation page forms follow.

1. `CheckInUnit` and `CheckOutUnit`, with the three permissions.
2. The room revenue policies, then `CancelUnit`, `MarkNoShow` and `ReinstateUnit`.
3. `ExtendStay`, `ChangeUnitDates`, `ProcessEarlyDeparture` and `ExtendDepartureTime`.
4. The unit **⋯** menu on the [reservation page](../12-reservations/06-reservation-page.md#units-tab), with the forms below.
5. `EarlyCheckOut` and the early mode of the check-out Dialog.

## Forms on the reservation page

Each form uses `FormOverlay` and the container from [Form containers](../design-system/05-form-containers.md).

| Action                                         | Container | Fields and notes                                                                                                                                                                                |
| ---------------------------------------------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Check in                                       | Dialog    | Shows the room, the registration card status, a card override (permission), Nationality when missing, and [Collect now](#collect-now)                                                           |
| Check out                                      | Dialog    | Shows the folio balance (split by room when it holds another room's charges) and the deposit credit. Collect the balance and use or refund the deposit. Moving charges is the Move charges page |
| Check out all                                  | Page      | Every room of a group settled and checked out together. See [Group check-out](05-group-check-out.md)                                                                                            |
| Cancel reservation                             | Dialog    | A row per unit with its fee, one Do not charge and reason, Also remove add-ons, and the money line. See [Cancel reservation](#cancel-reservation)                                               |
| Cancel unit, Mark no-show                      | Dialog    | Shows the policy fee ("No charge" inside the free window), Do not charge, a reason, Also remove add-ons                                                                                         |
| Reinstate                                      | Dialog    | A required reason. Re-prices the unit and shows the new total. The rooms are cleared, so staff assign them again                                                                                |
| Check out, early mode                          | Dialog    | The check-out Dialog plus an **Early departure** block: the old departure, the nights removed, the policy fee, Do not charge and a reason, and the balance with the fee                         |
| Change dates (also Extend and Early departure) | Dialog    | Dates and a live price difference. A shorter stay shows the early departure policy result                                                                                                       |
| Late check-out                                 | Dialog    | New time, the policy fee, Do not charge, a reason                                                                                                                                               |

**Do not charge** means the fee is not posted to the folio. It shows only for staff with `front-desk.waive-fees` (the code keeps the word "waive"). The reason is required and the choice is logged with the user. The fee is read-only text from the server.

## How the forms work

1. The unit **⋯** menu lists only the actions that `UnitStatus::canTransitionTo()` and the staff member's permission allow. The server sends the list.
2. The action opens its form in `FormOverlay`. Data the form needs (the fee, the price difference, free rooms) loads when the form opens, through a read-only route that changes nothing.
3. The form sends only choices: dates, a room, **Do not charge** and a reason. It never sends an amount. The server calculates the fee and the price again on submit.
4. Success closes the form with a toast. A refusal stays open and shows on the matching field, or as a form alert, through `ReservationRefusals`.
5. Each Action writes an activity entry with ids and the reason (for example `reservation.unit_cancelled`) and calls `SyncReservationStatus`.

- The cancellation window and "on the departure date" use the property business date (`CurrentBusinessDate`), not the system date.
- Cancel, no-show, reinstate, change dates and move room need `reservations.manage`. Check in and check out need their own permission. Late check-out needs `front-desk.check-out`.
- If the policy changes while a form is open and the fee differs on submit, the server refuses with `fee_changed` and the form shows the new amount for staff to confirm again.
- A fee posts through the folio routing rules. When the unit has no open folio, the Action creates it, as `RecordPayment` does for a deposit (`PostUnitPolicyFee` through `PostFolioCharge`).
- Reinstating a unit keeps a posted fee. The Reinstate form shows a note, and the fee is corrected with Reverse on the folio.
- A group "Check in all" lists one row per unit in the same Dialog. A failed unit does not stop the others, and each failure shows on its own row. It takes no payment: staff collect per unit afterwards.

### Built: cancel, no-show, reinstate and change dates

- **Menu.** `UnitAction` (enum) lists the menu actions and `UnitActionMenu` (`app/Services/Reservations/`) picks those the status and the user's permissions allow. The list goes to the page as `unit.actions`, so the browser has no rules of its own. Reinstate hides once the business date reaches the departure date.
- **Previews.** `UnitActionPreview` reads the fee, the price after a reinstate and the price after a date change. Each has a `POST .../units/{unit}/{action}/preview` route (`cancel`, `no-show`, `reinstate`, `dates`) that answers JSON and writes nothing, like the quote routes. The browser asks when the form opens and, for dates, again 300 ms after each change.
- **Actions.** `reservations.units.cancel`, `.no-show`, `.reinstate` and `.dates` (PUT) take `reservations.manage` in the route and call the Action. The Form Requests drop **Do not charge** without `front-desk.waive-fees` and the restriction reason without `rates.override-restrictions`.
- **Refusals.** `ReservationRefusals::unitErrors()` maps each refusal to a field. `fee_changed` lands on `expected_fee`, and the Dialog asks for a new preview.
- **Dialogs.** Cancel and No-show share one Dialog. **Also remove add-ons** shows only when the unit has add-ons that are not posted. **Reason** is always visible and needed with Do not charge.
- **Change dates** previews the extra nights without running the Action. Added nights are quoted alone, as in `ChangeUnitDates`. Extending an in-house unit uses the same Dialog with the arrival locked.

### Built: cancel reservation

- **Action.** `CancelUnits` runs `CancelUnit` for each ticked unit in unit id order inside one transaction. The first refusal rolls everything back and `CancelUnitsResult` names the unit and its room ("Room 106: ..."). `UnitStayResult::$fee` carries the fee each `CancelUnit` posted, for the total in the `reservation.cancelled` entry.
- **Routes.** `reservations.cancel` and `reservations.cancel-preview` sit on the reservation (the unit routes are `reservations.units.cancel`). The request sends `unit_ids`, `expected_fees` keyed by unit id, `do_not_charge`, `reason` and `remove_add_ons`.
- **Preview.** `UnitActionPreview::cancelAll()` returns one row per unit with the fee before and after tax, the net money held (folios that are not Voided, without the city ledger line) and the portal request. The Dialog works out the "owes" and "to refund" line from those figures.
- **Page.** The `canCancelReservation` prop (`reservations.manage` and at least one Confirmed unit) shows **⋯ More › Cancel reservation**.
- **Portal request.** `CancelUnits` clears `cancellation_requested_at` and `cancellation_requested_by` and says so in the log. The company portal's own request action is `RequestPortalCancellation` (module 23).

### Built: check in, check out and late check-out

- **Menu.** The menu lists Check in from the arrival date until the departure date, Check out from the departure date, and Late check-out on the departure date, the days each Action accepts. A shorter stay is changed first with Change dates. Early check-out (see [Early check-out](#early-check-out)) also lists Check out for a CheckedIn unit between the day after arrival and the day before departure, to a user with `reservations.manage`.
- **Previews.** `UnitActionPreview` gains `checkIn()`, `checkOut()` and `lateCheckOut()`. `CheckInCollection` and `CheckOutSettlement` (`app/Services/FrontDesk/`) work out Collect now and the deposit.
- **Check in.** `CheckInAndCollect` saves a missing nationality (to the primary guest, or the booker, through `UpdateGuest`) and runs `CheckInUnit`, then Collect now takes the taxes as an Advance payment and the deposit as a SecurityDeposit payment, with the keys `{uuid}:taxes` and `{uuid}:deposit`. All of it is one transaction, so a refused check-in or a refused payment saves nothing. The Collect now amounts come from `CheckInCollection` and the form sends no amount. The tax rows are the Scheduled tax rows of the unit's nights, so the guest pays what the night audit will post. A deposit already held on the unit folio is not asked again. Collect now shows only with `payment.record`.
- **Room tonight.** The preview sends `roomState` (`CheckInRoomState`): `assigned` shows the room, `auto` (no room, `auto_assign_rooms` on) shows "Assigned when you check in", and `none` shows "Not assigned for tonight", disables **Check in** and offers **Assign a room**, which opens the Move room Dialog (a user without `reservations.manage` sees "Ask someone who can assign rooms"). In `auto` mode a check-in can still be refused as `no_room_assigned` when nothing is free.
- **Nothing to collect.** When the offer has rows but nothing is due (every tax is exempt and there is no deposit), the Dialog shows "No collection is needed. You can check in the guest." in place of Collect now and the Method field, and sends `collect_now` false. The server also skips a 0.00 taxes total, so no payment is recorded either way.
- **More fields.** The Dialog adds a **Check in without a card** box (with `front-desk.check-in-without-card`, shown when a card is missing) and a **Credit limit override reason** (with `company.credit-override`, shown when the company is over its limit). They appear only when needed, so the usual form still has the five fields above.
- **Check out.** `CheckOutAndRefund` records the Collect balance payment (`RecordPayment`, Settlement, key `{uuid}:collect`), refunds the deposit (oldest deposit payment first, each through `RefundPayment`) and then runs `CheckOutUnit`, in one transaction. A refusal at any step rolls every step back. It refuses a refund above what is held before it writes. The Dialog needs `payment.record` for Collect balance and `payment.refund` for the refund, and opens check-out when the folio will be 0. **Keep for damages** links to the folio tab. See [Collect balance](#collect-balance).
- **Late check-out.** `reservations.units.late-check-out` calls `ExtendDepartureTime` with the same Do not charge and reason fields as the other fee forms.
- **Routes.** `reservations.units.check-in`, `.check-out` and `.late-check-out` with their `-preview` routes. Check in uses `front-desk.check-in`. Check out and late check-out use `front-desk.check-out`.

### Built: early check-out

- **Action.** `EarlyCheckOut` locks the unit, then calls `ChangeUnitDates` (arrival kept, departure the business date) and `CheckOutAndRefund`. It opens its own transaction and rolls back when either step returns a refusal, because those Actions return refusals and do not throw. A refusal code passes through unchanged. `not_early` is new.
- **Preview.** The `check-out-preview` route adds an `early` block (`UnitEarlyDepartureData`: booked departure, nights removed, the fee before tax and the fee with tax) when the unit is CheckedIn and its departure is after the business date. The fee with tax comes from `TaxCalculator`. A free fee has `feeTotal` 0.00: `ProcessEarlyDeparture` posts nothing for it, so a flat tax such as Tourism tax must not reach the balance or the refund default. A property with no early departure policy row gets the `policy_not_found` error in the preview.
- **Dialog.** The check-out Dialog switches to early mode when the preview has an `early` block: the fee block, Do not charge (with `front-desk.waive-fees`) and Reason. The balance and the default refund include the fee, as display math only. It posts to `reservations.units.early-check-out`, which has the extra `front-desk.check-out` middleware inside the `reservations.manage` group.
- **Menu.** `UnitActionMenu::isEarlyCheckOut()` lists Check out before the departure date for a CheckedIn unit that arrived before the business date, to a user with `reservations.manage`.
- **Night audit pending.** Replaced by [Night audit pending](#night-audit-pending): `EarlyCheckOutRule` asks `StayDay` and refuses `night_audit_pending` only for an Overdue audit (`StayDay::isAuditOverdue()`). It checks it after `arrived_today`. **Guest left yesterday** is refused with `left_yesterday_unavailable` when the audit is not pending (the audit may have run while the Dialog was open) or the night of the business date is already posted. It is also offered for a unit that departs on the stay day. The text below describes the first build. `EarlyCheckOut`, the preview and the group page row note all use that rule. The message names the business date (`20 Oct`), which reaches the response in `UnitStayResult::$rooms` and the preview through `ReservationRefusals::message()`. Decisions the doc did not cover: the unit menu lists **Check out** for an early stay-day check-out while the audit is pending (it hides it only when the stay day is not before the departure, where the plain check-out applies). The Dialog shows the message in its body and keeps the submit button disabled, as it does for `arrived_today`; `FormOverlay` has no option to hide its button.

### Built: check in all

- **Entry.** **Check in all** is in the **⋯ More** menu of the reservation page, for a reservation with 2 or more units that is not closed, to a user with `front-desk.check-in`. The page sends `canCheckIn`.
- **Rows.** `UnitActionPreview::checkInAll()` lists each Confirmed unit with its room, primary guest and unsigned cards. A unit is **ready** inside its stay. A unit that is not ready is dimmed and disabled, and its note says why and what to do: "Cannot check in yet: booked to arrive 15 Oct 2026 (in 2 days). To check in today, change the dates first." or "Cannot check in: the stay ended on 8 Oct 2026." A ready unit with no room tonight says "No room yet. One is given at check-in." when `auto_assign_rooms` is on, otherwise "No room for tonight. Assign a room before check-in." When no unit is ready, the Dialog says "No guest in this booking can check in today. The reason is under each name.", hides the payment note, and the disabled button reads **Check in**.
- **Action.** `CheckInAllUnits` calls `CheckInUnit` once for each chosen unit, ignores an id that is not a unit of the reservation and returns each unit's refusal. It takes no payment, saves no nationality and takes no credit override, because those belong to one unit. A **Check in without a card** box shows for a user with `front-desk.check-in-without-card`.
- **Errors.** A refused unit shows its message on its own row (the error key is `units.{id}`). The units that were checked in leave the list and the failed ones stay.

### Cancel unit

```
┌ Cancel unit · Deluxe King ──────────────────────┐
│ Tan Wei Ming · 12 Oct → 15 Oct · room 101       │
│                                                 │
│ Cancellation policy                             │
│ Free until 2 days before arrival                │
│ Today is 1 day before arrival                   │
│                                                 │
│ Fee         RM 280.00 + tax        (policy)     │  "No charge" inside the free window
│ ☐ Do not charge                                 │  Only with front-desk.waive-fees
│ Reason      [ Required with Do not charge ___ ] │
│                                                 │
│ ☐ Also remove add-ons                           │
│ The room is released.                           │
│                  [Keep unit] [Cancel unit]      │
└─────────────────────────────────────────────────┘
```

Mark no-show has the same layout, with the no-show policy and no free-until line. **Also remove add-ons** is unticked by default and removes only add-ons that are not posted to a folio yet.

### Change dates

```
┌ Change dates · Deluxe King ─────────────────────┐
│ Arrival    [ 12 Oct 2026 ]  (locked once in-house)
│ Departure  [ 14 Oct 2026 ]  2 nights (-1)       │
│ ┌ Price ─────────────────────────────────────┐  │
│ │ Was RM 840.00     Now RM 560.00            │  │
│ │ Difference                    - RM 280.00  │  │
│ └────────────────────────────────────────────┘  │
│ Shorter stay · early departure policy           │  Only when an in-house stay is shorter
│ Unused night 14 Oct   RM 280.00 + tax           │
│ ☐ Do not charge   Reason [ ____________ ]       │
│ Room 101 is free for the new nights  ✓          │
│                    [Cancel] [Save dates]        │
└─────────────────────────────────────────────────┘
```

An in-house unit can change only the departure date. Extend stay opens this form with Arrival locked. A Confirmed unit with a shorter stay shows no fee block.

### Late check-out

```
┌ Late check-out · Deluxe King ───────────────────┐
│ Departure   15 Oct · standard 11:00             │
│ New time    [ 16:00 ]   (+5 hours)              │  Later than now, before midnight
│ Fee         RM 140.00 + tax   (50% of a night)  │
│ ☐ Do not charge   Reason [ ____________ ]       │
│                    [Cancel] [Apply]             │
└─────────────────────────────────────────────────┘
```

### Collect now

A section of the check-in Dialog, shown only with `payment.record`. The form sends choices only: the three checkboxes, the method and the reference. The server calculates every amount.

```
┌ Check in · Deluxe King · 106 ─────────────── ✕ ┐
│ Lim Wei Jie · 12 → 15 Oct · 3 nights           │
│ Card  ✗ Not signed   [Open card]               │  only with card_required_for_check_in
│ ☐ Check in without card                        │  only with front-desk.check-in-without-card
│ Nationality   [ Singapore ⌄ ]                  │  only when missing
│                                                │
│ ☐ Room charges and tax            RM 321.60    │  unticked by default
│   Tourism tax        RM10 × 3     RM  30.00    │  shown while Room charges is unticked
│   International tax  RM3 × 3      RM   9.00    │
│ ☑ Security deposit                RM 100.00    │  only when the property sets an amount
│                                                │
│ ☑ Collect now        Total RM 139.00           │
│ Method [ Cash ⌄ ]   Reference [ ____ ]         │  reference for Card, Transfer, E-wallet
│                    [Cancel] [Check in]         │
└────────────────────────────────────────────────┘
```

With **Room charges and tax** ticked, the tax rows hide, because the full bill already holds them:

```
│ ☑ Room charges and tax            RM 321.60    │  nights, add-ons, every tax
│ ☑ Security deposit                RM 100.00    │
│ ☑ Collect now        Total RM 421.60           │
```

- **Room charges and tax** starts unticked: a booked guest often paid before arrival or pays at check-out. The walk-in form starts it ticked. It records one Advance for the unit's full bill (see [Walk-in check-in](#walk-in-check-in)). It is hidden when the reservation has a company and Master folio pays.
- The tax rows sum the Scheduled tax rows of every tax with **Collect at check-in** for the unit's nights, one row per tax. A foreign-only tax for a guest it does not apply to shows "Exempt" and adds 0. With no flagged tax, the tax rows are hidden.
- The amount is read-only. A guest who pays only part unticks **Collect now** and uses Record payment on the folio.
- The deposit box is ticked by default. It shows only when `security_deposit_amount` is above 0.
- With Collect now ticked, the server runs `CheckInUnit`, then `RecordPayment` for the taxes (Advance), then `RecordPayment` for the deposit (SecurityDeposit), with the same method and reference. It is all or nothing: a payment refusal shows on the method or reference field and saves nothing, not the check-in, the nationality or a payment that already went through. Staff fix the field and press **Check in** again.
- When the room charges route to a Master folio, the tax rows are hidden. The deposit still posts to the unit folio. Room charges and tax, when ticked, records the Advance on the Master folio, not the unit folio. So Collect now never records an Advance on a unit whose rooms go to the Master, and never meets the `advance_on_master` refusal ([Payments](../14-payments/01-payments-and-refunds.md#rules)). `CheckInCollection` and `RecordPayment` share `FolioRouter::roomChargesGoToMaster`.
- The Dialog counts seven fields at most: Without card, Nationality, Room charges, the deposit box, Collect now, Method and Reference.
- **Any tax can be collected at check-in**, not only Tourism tax. Each enabled tax with **Collect at check-in** gets its own row. `CheckInCollection` sums only the tax rows of the room nights (`night:{n}:tax:{id}`), so a flagged tax on an add-on or a rate plan inclusion is left out (see [Open](#open-walk-in)).

### Walk-in check-in

The booking form in Walk-in mode books, checks in and collects in one save. The form, the Payment section and the toast are in [Booking form: Walk-in](../12-reservations/05-booking-form.md#walk-in).

`CheckInWalkIn` (`app/Actions/FrontDesk/`) runs other Actions only, in one transaction:

1. `CreateBooking`. A refusal rolls everything back and returns the booking errors.
2. When the form asks for a payment, `WalkInPayment` totals the saved reservation from `ScheduledFolioRows` and the deposit. A total that is not `expected_total` rolls everything back (`amount_changed`).
3. For each unit, in unit order: `CheckInAndCollect`, with the walk-in's method, reference and `without_card`. Each call runs in its own savepoint, so a refused unit rolls back alone and the others go on. A refused unit stays Confirmed.
4. `SyncReservationStatus` once at the end.

It returns `WalkInResult`: the reservation (null when the booking was refused), the booking refusal, and one row per unit with its refusal code or null, and the amount collected.

**What each unit pays.** `CheckInCollection` gains the full bill of the unit, and `CollectNowData` gains `roomCharges`:

| `roomCharges` | `CheckInAndCollect` records                                                                                                      |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| true          | One Advance for the unit's full bill (every Scheduled row of the unit: nights, add-ons, taxes, less discounts), then the deposit |
| false         | The Collect at check-in taxes as one Advance, then the deposit, as the check-in Dialog does today                                |

- The full bill goes to the folio that gets the unit's room charges. A unit whose room charges go to the Master folio pays its share as an Advance on the Master folio. `RecordPayment` allows that; it refuses an Advance only on a Unit folio whose room charges go to the Master (`advance_on_master`).
- A reservation with a company and Master folio pays collects no room charges (the form hides the box). The deposit still goes to each unit folio.
- `RecordPayment` does not check the stay stage, so an Advance right after check-in is accepted (checked in the code). Only the payment form limits the purposes by stage (`PaymentPurpose::forStage`).
- The keys are `{uuid}:{unit}:bill`, `{uuid}:{unit}:taxes` and `{uuid}:{unit}:deposit`, so a repeated submit returns the same payments.
- The check-in Dialog sends `roomCharges` from its own **Room charges and tax** box (unticked by default; see [Collect now](#collect-now)). A walk-in unit refused for `card_missing` is finished there after the guest signs, so the guest still pays the whole stay at the desk.

**The preview: a trial booking, rolled back.** `WalkInPayment::preview()` (`app/Services/FrontDesk/`) answers `reservations.walk-in-payment` before anything is kept. It opens a transaction, runs `CreateBooking` with the form's data, reads the new reservation through `CheckInCollection` (the full bill, the Collect at check-in taxes and the deposit of each unit, from `ScheduledFolioRows`), then rolls back. The save runs the same code, so the preview and the save can only differ when a rate, tax or deposit changed in between (`amount_changed`).

- Why not price the lines without saving: the night prices are set inside `AddUnit` (rate quote, package spread, price override), and `NightChargeQuote` needs saved nights. A second copy of that pricing could drift from the folio by a cent. The trial booking cannot.
- What the trial costs: it locks the room type rows (`AssignRoom`) and the reservation `document_sequences` row for the length of the request, about 100 ms. Nothing is kept: the reference number, the activity log and the channel outbox lines roll back with it.
- **Channel sync waits for the commit.** `RecordChannelAriChange` dispatches `FlushChannelAriJob` with `->afterCommit()`, so a rolled-back trial never queues a flush. Real bookings are unchanged: they commit, then the flush is queued (see [ARI sync](../21-channels/04-ari-sync.md)).
- A refused trial answers with the booking errors (`ReservationRefusals`), the same as a save.
- **Nationality.** Foreign-only taxes follow the booker's nationality for every unit, as `ScheduledFolioRows` and `PostCharge` do, not each unit's guest. The trial books the guests as the form has them, so the preview follows the same rule.
- A test books the same input with `CreateBooking` and checks that the preview equals that reservation's Scheduled rows.

**Refusals.** The booking refusals, `amount_changed`, every `CheckInUnit` refusal per unit, and every `RecordPayment` refusal per unit. `ReservationRefusals` maps them as the booking form and the check-in Dialog do.

**Permissions.** `reservations.manage` and `front-desk.check-in` for the save. `payment.record` for the Payment section; without it the request drops the payment fields. `front-desk.check-in-without-card` shows **Check in without card** on the walk-in form, as on the check-in Dialog; it applies to every unit, and the request drops it for staff without the permission. Unticked, a unit without a signed card stays Confirmed and goes through the signing flow.

**Activity.** Each Action logs as it does alone (`reservation.created`, check-in, `payment.recorded`). `CheckInWalkIn` adds one `reservation.walk_in` entry with the counts and the total collected.

#### Open (walk-in)

- **Confirmed gap:** a tax with **Collect at check-in** on an add-on (`addon:{id}:tax:{tax}`) or a rate plan inclusion (`night:{id}:incl:{inclusion}:tax:{tax}`) is not counted by `CheckInCollection`, which matches `night:{n}:tax:{id}` only. The check-in Dialog has the same gap today. With Room charges and tax ticked the full bill holds these taxes, so only the unticked case is short. It is fixed apart from the walk-in.

### Check out

The check-out Dialog is the one Dialog with the `lg` size ([Form containers](../design-system/05-form-containers.md#sizes)). Desktop (1024 px and wider):

```
┌─ Check out early · Unit 202 · Hanami Ume ──────────────────────────────────────┐
│ ⚠ Hotel day 21 Oct · Today 22 Oct. Night audit not run yet.                    │
│                                                                                │
│  ⓘ Last night (21 Oct) is added to the   │ ╭────────────────────────────────────╮ │
│    bill now. 22 and 23 Oct are removed.  │ │ Booked departure      24 Oct 2026  │ │
│  When did the guest leave?               │ │ Nights removed                  2  │ │
│  (•) Left yesterday   ( ) Stayed         │ │ Early departure fee    No charge   │ │
│  Reason [ e.g. Flight changed, ...    ]  │ │ ────────────────────────────────── │ │
│                                          │ │ Room · Dive Package (added now)    │ │
│  Security deposit                        │ │ Charges                   1,103.07 │ │
│  (•) Use toward the bill ( ) Refund it   │ │ Paid                       −30.00  │ │
│  ( ) Keep part for damages               │ │ Deposit                   −100.00  │ │
│  Method  [ Cash                     ▾ ]  │ │ To pay                 MYR 973.07  │ │
│  Collect by Cash           MYR 973.07    │ ╰────────────────────────────────────╯ │
│  Remaining after check-out   MYR 0.00    │                                        │
│                                                  [ Cancel ] [ Collect and check out ]│
└────────────────────────────────────────────────────────────────────────────────┘
```

- **Left column, decisions:** the pending-audit ⓘ line, the "When did the guest leave?" or overdue cards, **Do not charge** and the reason, **Security deposit**, the method and the collect or refund summary. The pending-audit strip spans both columns at the top.
- **Right column, the summary:** a muted panel with a rounded border (`rounded-lg border bg-muted p-4`). In early mode it starts with the facts (**Booked departure**, **Nights removed**, **Early departure fee**), then the "(added now)" lines and the folio block. Read only. Always open on desktop. The two columns have a minimum height (`lg:min-h-96`) so the Dialog does not jump between plain and early mode.
- **Phone and tablet (below 1024 px):** one column. The bill is a `Collapsible` at the top, closed by default. Closed it shows "Bill", the amount to pay (or to refund) and "Charges X · Paid Y"; open, every line and the folio block. The decisions follow.

**Security deposit.** A section with that title, shown only when a deposit is held and the user may refund. What it shows depends on whether there is a real choice (`checkOutFigures().choosesDeposit`: the guest owes money before any damage charge):

| Situation                                                | Control                                                                                                                                                          |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The guest owes money                                     | Cards: **Use toward the bill** (the default), **Refund it** and, with the damage item, **Keep part for damages** (the deposit offsets the damage, then the bill) |
| The deposit already settles the bill (the folio is at 0) | Text "MYR 100.00 used toward the bill. Nothing to refund." and, with the damage item, the checkbox **Keep part of it for damages**                               |
| The folio holds a credit                                 | The deposit can only be refunded, so there is no choice and the refund shows directly                                                                            |

The refund by the method shown is **Refund it**. A damage charge shows its Damage and Note fields under the cards. The amounts and the server are unchanged; only the controls changed (they were a checkbox and a second checkbox). See [Keep the deposit for damages](#keep-the-deposit-for-damages).

**The folio block.** The Dialog breaks the balance down: **Charges** (every line that is not a payment, so taxes, discounts and reversals count; in early mode it includes the fee shown above), **Paid** (every payment and refund but the deposit held, hidden at 0), **Deposit held**, then **To pay**, **Credit** or **Settled**. Charges + Paid − Deposit held is the balance, so the rows never disagree. The preview sends `charges` and `paid` (`CheckOutSettlement`). The summary under the method no longer repeats "Deposit used toward the bill": the deposit is already in the block.

### Collect balance

A section of the check-out Dialog, shown only with `payment.record` and when the guest still owes money. It replaces the "Collect the balance with Record payment on the folio" line, which stays for a user without `payment.record`.

```
┌ Check out · Standard Double ─────────────── ✕ ┐
│ Ong Kok Leong · 104 · departs 09 Oct 2026     │
│                                               │
│ Unit folio                                    │
│   Charges             MYR 596.00              │
│   Deposit held       −MYR 200.00              │
│   To pay              MYR 396.00              │
│                                               │
│ ( ) Use toward the bill                       │
│ (•) Refund deposit · Cash   [ 200.00 ]        │
│ ( ) Keep for damages                          │
│                                               │
│ Collect balance               MYR 596.00      │
│ Method [ Card ⌄ ]   Reference [ ____ ]        │
│ ───────────────────────────────────────────── │
│ Collect by Card               MYR 596.00      │
│ Refund by Cash               −MYR 200.00      │
│ Remaining after check-out     MYR 0.00        │
│        [Cancel] [Collect, refund and check out]│
└───────────────────────────────────────────────┘
```

| Deposit choice                | Collect                        | Server steps                    |
| ----------------------------- | ------------------------------ | ------------------------------- |
| Use toward the bill (default) | The balance                    | Payment, check-out              |
| Refund deposit                | The balance + the refund       | Payment, refund, check-out      |
| Keep for damages              | Nothing; post the charge first | Same as before: folio tab first |

Since then, **Keep for damages** posts the charge in the Dialog ([Keep the deposit for damages](#keep-the-deposit-for-damages)).

- **Use toward the bill** shows only when the guest owes money, and is then the default. With only the deposit left, **Refund deposit** stays the default.
- The collect amount is read-only: the balance plus the refund. The form sends it with the method and reference. The server records it as it is, and `CheckOutUnit` then needs the folio at 0, so an amount that no longer settles it (a charge posted while the form was open) is refused as `balance_not_settled` and nothing is written.
- The payment, the refund and the check-out are one transaction. A payment refusal shows on the method or reference field.
- The button names the steps: **Check out**, **Collect and check out**, **Refund and check out** or **Collect, refund and check out**.
- A method with a surcharge adds the fee as a charge, and the payment covers it, so the folio still reaches 0. The Dialog does not show the fee yet.
- Transfer to the Master folio is not in the Dialog. Staff use the folio tab.

### Refund the credit

Built. Before this change `CheckOutAndRefund` refunded only security deposits (`heldDeposits()`), so a credit from an Advance or a Settlement payment, for example a prepaid stay cut short by [early check-out](#early-check-out), stayed on the folio, and check-out was refused as `balance_not_settled`. Staff had to refund on the folio tab first.

Built: `CheckOutSettlement::refundPool()` lists the refund pool: the security deposits held (oldest first), then the other completed payments that still hold money (oldest first). Each part goes through `RefundPayment`.

Built, decisions the doc did not cover:

- A credit above the deposit is refunded as a whole, so its amount is read only and the Dialog lists each part (purpose, method and the reference after "ref"). Below the deposit the amount stays editable, as before.
- The early check-out preview recomputes the default with the fee the form will post, so the refund matches the folio after the fee. **Do not charge** changes the amount in the Dialog. Its parts are listed only while the amount is the server's default.
- The preview carries `refundable`, what the payments can give back in all. The Dialog caps the refund at it.
- A refusal for a limit (above the larger of the deposit held and the credit, or above `refundable`) is `refund_exceeds_payment`, and nothing is written.

The check-out Dialog, single and early, refunds the whole credit from every payment that still holds money.

```
┌ Check out early · Deluxe King ────────────── ✕ ┐
│ Tan Wei Ming · 101 · booked to 22 Oct          │
│ Leaves today, 20 Oct · 2 nights removed        │
│                                                │
│ Early departure fee        Free                │
│ Unit folio                                     │
│   Charges                  MYR 100.00          │
│   Paid                    −MYR 300.00          │
│   Deposit held            −MYR 100.00          │
│   Credit                   MYR 300.00          │
│                                                │
│ Refund                                         │
│   Security deposit · Cash      MYR 100.00      │
│   Advance · Card ref 4411      MYR 200.00      │
│ ────────────────────────────────────────────── │
│ Remaining after check-out      MYR 0.00        │
│               [Cancel] [Refund and check out]  │
└────────────────────────────────────────────────┘
```

- **The pool.** `CheckOutAndRefund` refunds from `heldPayments()`: every Completed payment of the unit's Open folios with money left, whatever its purpose. Security deposits go first, then the other payments oldest first, as Move charges does. Each part runs through `RefundPayment`, with the key `{uuid}:{payment id}` as today.
- **The amount.** At most the larger of the deposit held and the credit. With a credit above the deposit, the refund is the credit and is read only: any other amount cannot settle the folio. With the guest owing money, only the deposit is offered, as today ([Collect balance](#collect-balance)). A higher amount is refused as `refund_exceeds_payment` before anything is written.
- **The method.** Each part goes back by the method of its own payment (`RefundPayment` copies it). The Dialog lists each part with its purpose, method and reference, so staff know where the money goes. Decided: the Dialog does not refund by another method. Staff use **Refund credit** on the folio tab first ([Refund folio credit](../14-payments/01-payments-and-refunds.md#refundfoliocredit), `payment.refund-other-method` with a reason), then check out with the folio at 0.
- **Rows.** The last row of the [folio block](#check-out) reads **Credit** with the amount to give back. **Keep for damages** stays: post the charge first, and the credit falls. Since then, the Dialog posts it ([Keep the deposit for damages](#keep-the-deposit-for-damages)).
- **Money that cannot be refunded.** A credit from a line with no payment row (a City ledger line) cannot go through `RefundPayment` (`holdsUnrefundable()`). The Dialog shows "Part of this credit cannot be refunded here. Open the folio." and offers no refund.
- **Preview.** `UnitCheckOutPreviewData` gains `refundParts` (purpose, method, reference and amount of each part, in refund order) from `CheckOutSettlement`. `refundDefault` becomes the credit when it is above the deposit.
- **Group check-out** uses the same pool for a CheckedIn row: `GroupCheckOutPreview` stops limiting its refund to deposits ([Group check-out](05-group-check-out.md#rows)). A room checked out early from the page uses the single Dialog, so it gets the fix with it.
- Permissions do not change: a refund needs `payment.refund` and support access needs money corrections allowed. Front desk holds `payment.refund` by default, so it refunds a deposit at check-out. The check-out Dialogs refund to the payment's own method only.

### Keep the deposit for damages

Built. Before, **Keep it for damages? ›** only linked to the folio tab: staff posted the charge there, came back and refunded the rest. The Dialog now posts the damage charge itself, in the same transaction as the payment, the refund and the check-out.

The deposit payment line stays as it is, because the ledger is immutable. The damage charge is a new line, and the deposit credit offsets it.

```
│  Security deposit                        │  Charges                       1,103.07│
│  [✓] Keep part of it for damages         │  Paid                          −30.00  │
│      Damage [ e.g. 50.00 ] Note [ ... ]  │  Damage                         33.00  │
│      Posts to Damage, plus its tax.      │  Deposit held                 −100.00  │
│  Collect balance          MYR 1,006.07   │  To pay                    MYR 1,006.07│
│  Method  [ Cash                     ▾ ]  │                                        │
│  Damage 33.00 · Collect 1,006.07 by Cash │                                        │
```

- **The control.** **Keep part for damages** is the third card of the Security deposit section when the guest owes money, or a checkbox when the deposit already settles the bill. It shows when a deposit is held and the user holds `folio.post-charge` (the preview's `damageItem`). No setup is needed: it posts to the built-in **Damage** item. The old **Keep it for damages? ›** link is gone.
- **The deposit goes to the damage first.** While the damage is chosen, **Use toward the bill** and **Refund it** are not offered: the deposit always offsets the damage and the bill, and only what is left is refunded.
- **Damage amount.** Staff type it, before tax. It starts empty, or at the charge item's default price when the item has one. It must be above 0. Check-out stays closed until the server has priced it.
- **Tax.** The damage posts with its item's taxes, like any charge. The preview takes `damage_amount` (asked 300 ms after typing stops) and sends `damageTotal`, the amount with tax, and a `refundDefault` and `refundParts` that already count it. The browser adds `damageTotal` to the balance for display only.
- **Note.** Optional, up to 100 characters. It goes after the item name in the line description, for example "Damage · Broken lamp".
- **Above the deposit.** Allowed. With a deposit of 100.00 and damage of 250.00 (no tax), nothing is refunded and 150.00 is collected.
- **The bill column.** A **Damage** row (with tax) shows while the box is ticked and an amount is priced, so Charges + Paid + Damage − Deposit held is the balance.
- **The summary** under the method lists the damage first, then Collect or Refund, then Remaining after check-out.
- **Early and overdue check-out** take it too: `EarlyCheckOut` and `CheckOutOverdueStay` pass it to `CheckOutAndRefund`.
- **Group check-out** does not offer it. A room with damage is checked out through the single Dialog.
- **Fixing it later.** The charge is a normal folio line. Reopen the folio, reverse the line, and post the right amount or refund the difference.

| Example (deposit 100.00, folio otherwise at 0, no tax) | Damage | Refund | Collect |
| ------------------------------------------------------ | ------ | ------ | ------- |
| Small damage                                           | 30.00  | 70.00  | 0.00    |
| Damage equal to the deposit                            | 100.00 | 0.00   | 0.00    |
| Damage above the deposit                               | 250.00 | 0.00   | 150.00  |

**Server.** `CheckOutUnitRequest` takes `damage_amount` and `damage_note` (`damage()`), and `CheckOutAndRefund` runs, in one transaction:

| Step | Action                                                                                | Key                   |
| ---- | ------------------------------------------------------------------------------------- | --------------------- |
| 0    | Post the pending night while the audit is pending (`PostPendingNight`), as before     | the audit's keys      |
| 1    | Post the damage charge (`PostCharge`, the built-in Damage item, the unit's own folio) | `{uuid}:damage`       |
| 2    | Collect what the form showed (`RecordPayment`, Settlement)                            | `{uuid}:collect`      |
| 3    | Refund what is left of the deposit (`RefundPayment`, deposits first)                  | `{uuid}:{payment id}` |
| 4    | Check out (`CheckOutUnit`)                                                            | —                     |

- The damage lands on the unit's own folio (`ownFolio`), not through routing, because the deposit it offsets is there. It posts on the business date.
- `DamageChargeItem` (`app/Services/FrontDesk/`) finds the item by its DAMAGE system code (active) and prices it with `TaxCalculator`, as `PostCharge` does.
- A refusal at any step rolls back every step. A user without `folio.post-charge` gets an error on `damage_amount` from the request. A Damage item that was turned off is refused as `damage_not_allowed`, and an amount an item with a fixed price does not allow as `damage_price_fixed`, both on `damage_amount`.

**The Damage item.** Every property has a built-in **Damage** charge item, so nothing has to be set up. It is a [starter item](../06-catalogues/02-charge-items.md#starter-items) on the DAMAGE system code (category Other): no default price (staff type the amount), no tax until the property adds one. Staff can rename it, price it or add a tax; the Dialog still finds it by its code. An owner who turns it off hides the damage choice. A migration added the code and the item to every property that had finished Basics.

An earlier version asked the owner to pick the item in Registration card settings (`property_settings.damage_charge_item_id`). Staff missed the setting, so the column and the select were removed before release.
