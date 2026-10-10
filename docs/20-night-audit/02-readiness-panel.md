# Readiness panel

## Purpose

Fix problems during the day so the audit has nothing to do at night. The panel is a Sheet, **Run Night Audit**, opened from the Front desk sidebar. It also runs the audit.

## Service

`AuditReadiness` (`app/Services/NightAudit/`) reads the checks for the current business date and returns `ReadinessItem` rows, blockers first. It writes nothing and is scoped to the property. `RunNightAudit` calls it for the gate below.

## Checks

| Check (`AuditCheck`)                                      | Rule on business date D                                                                                                                                                                                                                                                                                          | Blocker | Row button                                          |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | --------------------------------------------------- |
| `InHouseWithoutRoom` No room or bed tonight               | CheckedIn, with a night on D that has no room and no bed                                                                                                                                                                                                                                                         | **Yes** | **Assign** (`AutoAssignRooms`)                      |
| `ArrivalNotCheckedIn` Not checked in                      | Confirmed, arrival on or before D, `late_arrival_expected` off                                                                                                                                                                                                                                                   | No      | **Open** the reservation                            |
| `DepartureNotCheckedOut` Not checked out                  | CheckedIn, departure on or before D. A [day use](../12-reservations/10-day-use.md#night-audit) shows "Day use · ended 14:00" in the detail                                                                                                                                                                       | No      | **Open** the reservation                            |
| `FolioOpenAfterCheckOut` Folio still open                 | An open Unit folio on a CheckedOut unit. Check-out closes the folio at 0 and it closes itself again at 0, so this means the guest left owing money                                                                                                                                                               | No      | **Open** the reservation                            |
| `MasterNotBilled` Master folio not billed                 | An Open Master or ThirdParty folio with a balance above 0, on a reservation with no CheckedIn or Confirmed unit and at least one CheckedOut unit. With or without a payer. A company-paid one also counts in the company's [credit exposure](../15-city-ledger/04-credit-control-and-reports.md#credit-exposure) | No      | **Open folio**                                      |
| `MoneyHeldOnClosedBooking` Money held on a closed booking | An Open folio with a balance below 0: a Unit folio of a Cancelled or NoShow unit, or a Master or ThirdParty folio on a reservation with at least one unit and no CheckedIn or Confirmed unit                                                                                                                     | No      | **Open folio**                                      |
| `CarriedException` Left from an earlier audit             | An open `night_audit_exceptions` row with a unit                                                                                                                                                                                                                                                                 | No      | **Retry** (a room charge), **Open** the reservation |

A row is one reservation with one problem, so a group booking is one row, not one for each unit. The two folio checks are one row per reservation too: the detail sums the amount and names the folios ("Master F-0031 · MYR 1,700.00 owed", "MYR 250.00 to refund · F-0044"), with the payer company or else the booker as the guest. A reservation whose units are all NoShow is Completed, so these checks read unit statuses, not the reservation status.

The two folio checks are warnings and the audit never acts on them (the wastays lesson: company billing must never trap the audit). The sheet summary therefore no longer says the audit settles warnings: "Ready to run. 2 reservations need a look." A reservation with several units can be opened to treat one unit by itself. An item with a policy is a warning: the audit runs and the policy decides (see [Audit run](03-audit-run.md#steps)).

## What a row says

Each row gives, in plain words: who and which reservation, what is wrong ("3 units — not checked in"), what the audit does if nobody touches it (**If you leave it**), and the buttons. `ReadinessAdvice` writes the words from the property's own policies, so they are what the audit will really do.

| Check                        | If you leave it                                                                                                                                                                                                                                                                                                               | Buttons                                      |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| No room or bed tonight       | The audit cannot run until the unit has a room.                                                                                                                                                                                                                                                                               | **Assign a room**                            |
| Not checked in               | Keep as late arrival: kept, nothing charged. Mark no-show: marked no-show and the fee is charged.                                                                                                                                                                                                                             | **Check in** (link), **Mark no-show**        |
| Not checked out              | Extend one night: one more night is charged. Block departure: no new night, you finish it later.                                                                                                                                                                                                                              | **Check out** (link), **Extend 1 night**     |
| Folio still open             | The audit does not touch it.                                                                                                                                                                                                                                                                                                  | **Open folio** (link)                        |
| Master folio not billed      | The audit does not touch it. Settle the folio to the city ledger, or collect the balance and close it.                                                                                                                                                                                                                        | **Open folio** (link, to that folio)         |
| Money held on closed booking | The audit does not touch it. Refund the money, or post the charge it covers.                                                                                                                                                                                                                                                  | **Open folio** (link, to that folio)         |
| Left from an earlier audit   | The reason, in words, from the stored refusal code (`folio_not_open` becomes "The folio was closed, so the night could not be charged."; `folio_settled_to_city_ledger` becomes "The folio was settled to the city ledger, so the charge could not be posted. Open a third-party folio for the company and route it there."). | **Retry** (RoomCharge only), **Open** (link) |

Three buttons run a fix in place (**Assign**, **Mark no-show**, **Extend 1 night**). Three open a Dialog of the reservation page over the sheet:

| Button           | Dialog             | Notes                                                                                                               |
| ---------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------- |
| **Check in all** | `CheckInAllDialog` | A group row: one row for each unit, as on the reservation page.                                                     |
| **Check in**     | `CheckInDialog`    | One unit, with Collect now. A unit with no room offers **Assign a room**, which opens the reservation page.         |
| **Check out**    | `CheckOutDialog`   | One unit, with the folio balance and the deposit refund. A group opens its units, because each has its own balance. |

`AuditDialogHost` loads what a Dialog needs from `GET night-audit/reservations/{reservation}` (`NightAuditReservationData`: the reservation, its units with the actions the user may use, the payment options) only when the button is pressed, and the sheet reloads when the Dialog closes. The Dialogs are the reservation page's own, so their rules, previews and refusals are the same. This is the one place a Dialog opens over another overlay (see [Form containers](../design-system/05-form-containers.md#hard-rules)). A user without `front-desk.check-in` or `front-desk.check-out` gets a link to the reservation page on a group row, and no button on a unit row. **Open folio** and **Open** are links, because the folio tab is a full page.

| Fix (`AuditFix`) | Action it calls   | Notes                                                                                                               |
| ---------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------- |
| `assign`         | `AutoAssignRooms` | A free room or bed for the whole stay.                                                                              |
| `no-show`        | `MarkNoShow`      | The fee of the no-show policy. The button asks first and names the total fee (`MYR 180.00`).                        |
| `extend`         | `ExtendStay`      | One night, only for a unit that departs on the business date. A unit that is further past is refused: check it out. |

`RunAuditFix` runs a fix on the units the sheet lists for that reservation, or on one unit. It touches no unit that is not listed, so a unit that changed since the sheet loaded is skipped. The user is the actor, so the activity log names them. A unit an Action refuses does not stop the others: the answer says how many were done and why the rest were not. Fixes need `reservations.manage` as well as `night-audit.run`.

An exception stops being listed when someone resolves it. **Retry** resolves a room charge exception (below). The other types have no way to be resolved yet, so they stay until the exceptions item adds one.

## Retry a room charge

A `RoomCharge` exception means the night of that audit's business date was never posted. A closed date takes no postings, so the night can only post later. Fix the cause first (reopen the folio, open a third-party folio), then press **Retry**.

`RetryRoomCharge` (`app/Actions/NightAudit/`) takes the user and the exception:

1. The night is the unit's night on the business date of the exception's audit.
2. It calls `PostRoomCharges` for that night, with the user as the actor. The lines are posted on the **current** business date, like every posting. The room line keeps its key `night:{unit_night_id}` and its source, the unit night. Its description adds the night: "Room · night of 8 Oct".
3. Posted, or already posted (the key exists): the exception is resolved (`resolved_at`, `resolved_by`) and leaves the panel.
4. Refused: nothing is written or logged and the exception stays open. The answer gives the reason of this try in words.

| Check, in order                                | Refusal                 |
| ---------------------------------------------- | ----------------------- |
| The exception is open and of type `RoomCharge` | `exception_not_retried` |
| The unit is CheckedIn or CheckedOut            | `unit_not_postable`     |
| Any refusal of `PostRoomCharges`               | that refusal            |

- One transaction with the unit locked, like the audit. Two clicks post once, because of the key.
- A posted or already posted night logs one activity on the reservation (`reservation.room_charge_retried`), with the unit, the exception and the night.
- A night whose stored price cannot hold its inclusions (`exceeds_night_amount`) is refused again. Its price was stored before the fix to [package nights](../08-rates/03-pricing-strategies.md#package-nights-and-inclusions), and Retry never changes a stored price.
- Retry needs `reservations.manage` as well as `night-audit.run`, like the fixes. Like a fix, a refusal shows on the sheet and the sheet reloads.
- The button shows on a row with one carried room charge, and on each unit of a row with several. `ReadinessItem::retryExceptionId` carries the exception.
- `ReadinessAdvice` says `exceeds_night_amount` in words: "The night's price is less than its inclusions, so it could not be split."

## Not built

| Check                  | Why                                                                                                |
| ---------------------- | -------------------------------------------------------------------------------------------------- |
| Pending payments       | Nothing creates a Pending payment: `RecordPayment` completes at once. Add it with online payments. |
| Channel booking errors | Module 21.                                                                                         |
| Overbooked nights      | Only channel bookings can overbook. Module 21. `NightAvailability` already shows the count.        |
| Mark late arrival      | Staff set `late_arrival_expected` on the booking. A later fix can add an Action.                   |

## The gate

`AuditReadiness::gate()` says whether a run that would start the audit may go ahead. `RunNightAudit` and the run route both use it. A date that is already Auditing is under way, so nothing gates it.

| Refusal                   | When                                                                                                                                                                             |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `business_date_not_ended` | The system date in the property time zone is not after the business date. The day has not ended, so closing it would put the business date ahead of the calendar. Checked first. |
| `blocked_by_readiness`    | A blocker is open.                                                                                                                                                               |

A forced run (`night-audit.force` and a reason) skips both. A run that resumes a Failed audit is never gated. A scheduled run never forces, so it stops at either. The schedule runs after `audit_time` on the next day, so a scheduled run is never early.

## The sheet

Opened by the **Run Night Audit** button in the Operations group (shown to users with `night-audit.run` on a Live property). The nav item has an `action` and the sheet loads its data from `GET /p/{code}/night-audit` as JSON (`night-audit.show`), so nothing loads until it opens.

| State     | What it shows                                                                                                                                                              | Buttons                  |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------ |
| Ready     | "Closes 12 Oct and opens 13 Oct". **Fix before running** (blockers), **Needs a look** (warnings), or "Everything is ready"                                                 | Cancel, **Run audit**    |
| Blocked   | The same, with **Not over yet** first when the date has not ended. **Run audit** is disabled. Users with `night-audit.force` see **Force close…**, which asks for a reason | Cancel, **Force close**  |
| Running   | The six steps with their counts, refreshed every 3 seconds                                                                                                                 | Close                    |
| Failed    | The step it stopped at. Finished steps are kept                                                                                                                            | Cancel, **Resume audit** |
| Completed | "Now trading on 13 Oct" and the number of items that need a look                                                                                                           | Done                     |

The sheet shows what its own run is doing until the audit of that date has finished, even before the queue picks the job up. A date that is Auditing when the sheet opens shows as Running or Failed.

## Routes

All under `/p/{code}/night-audit` (`night-audit.`), behind `live` and `can:night-audit.run`.

| Route                                                              | Purpose                                                                                                                                                   |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /` (`show`)                                                   | The sheet data.                                                                                                                                           |
| `POST run`                                                         | Queue `RunNightAuditJob` for the current user. Body: `force`, `reason`. A blocker with no force gives 422. `force` without `night-audit.force` gives 422. |
| `POST reservations/{reservation}/units/{unit}/assign`              | `AutoAssignRooms`. Needs `reservations.manage`. 422 with a message when nothing is free.                                                                  |
| `POST exceptions/{nightAuditException}/retry` (`exceptions.retry`) | `RetryRoomCharge`. Needs `reservations.manage`. An exception of another property is a 404. A refusal answers 422 with the reason in words.                |

## Lessons from wastays

7 pre-close and 8 post-close blocking checks, most needing a person at night.
