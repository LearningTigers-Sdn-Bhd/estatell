# Readiness panel

## Purpose

Fix problems during the day so the audit has nothing to do at night. The panel is a Sheet, **Run Night Audit**, opened from the Front desk sidebar. It also runs the audit.

## Service

`AuditReadiness` (`app/Services/NightAudit/`) reads the checks for the current business date and returns `ReadinessItem` rows, blockers first. It writes nothing and is scoped to the property. `RunNightAudit` calls it for the gate below.

## Checks

| Check (`AuditCheck`)                          | Rule on business date D                                        | Blocker | Row button                     |
| --------------------------------------------- | -------------------------------------------------------------- | ------- | ------------------------------ |
| `InHouseWithoutRoom` No room or bed tonight   | CheckedIn, with a night on D that has no room and no bed       | **Yes** | **Assign** (`AutoAssignRooms`) |
| `ArrivalNotCheckedIn` Not checked in          | Confirmed, arrival on or before D, `late_arrival_expected` off | No      | **Open** the reservation       |
| `DepartureNotCheckedOut` Not checked out      | CheckedIn, departure on or before D                            | No      | **Open** the reservation       |
| `FolioOpenAfterCheckOut` Folio still open     | An open Unit folio on a CheckedOut unit                        | No      | **Open** the reservation       |
| `CarriedException` Left from an earlier audit | An open `night_audit_exceptions` row with a unit               | No      | **Open** the reservation       |

A row is one reservation with one problem, so a group booking is one row, not one for each unit. A reservation with several units can be opened to treat one unit by itself. An item with a policy is a warning: the audit runs and the policy decides (see [Audit run](03-audit-run.md#steps)).

## What a row says

Each row gives, in plain words: who and which reservation, what is wrong ("3 units — not checked in"), what the audit does if nobody touches it (**If you leave it**), and the buttons. `ReadinessAdvice` writes the words from the property's own policies, so they are what the audit will really do.

| Check                      | If you leave it                                                                                                                           | Buttons                                  |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| No room or bed tonight     | The audit cannot run until the unit has a room.                                                                                           | **Assign a room**                        |
| Not checked in             | Keep as late arrival: kept, nothing charged. Mark no-show: marked no-show and the fee is charged.                                         | **Check in** (link), **Mark no-show**    |
| Not checked out            | Extend one night: one more night is charged. Block departure: no new night, you finish it later.                                          | **Check out** (link), **Extend 1 night** |
| Folio still open           | The audit does not touch it.                                                                                                              | **Open folio** (link)                    |
| Left from an earlier audit | The reason, in words, from the stored refusal code (`folio_not_open` becomes "The folio was closed, so the night could not be charged."). | **Open** (link)                          |

Three buttons run a fix in place. A **link** goes to the reservation page and closes the sheet, because check-in, check-out and the folio have forms (payment, refund, balance) that stay on that page.

| Fix (`AuditFix`) | Action it calls   | Notes                                                                                                               |
| ---------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------- |
| `assign`         | `AutoAssignRooms` | A free room or bed for the whole stay.                                                                              |
| `no-show`        | `MarkNoShow`      | The fee of the no-show policy. The button asks first and names the total fee (`MYR 180.00`).                        |
| `extend`         | `ExtendStay`      | One night, only for a unit that departs on the business date. A unit that is further past is refused: check it out. |

`RunAuditFix` runs a fix on the units the sheet lists for that reservation, or on one unit. It touches no unit that is not listed, so a unit that changed since the sheet loaded is skipped. The user is the actor, so the activity log names them. A unit an Action refuses does not stop the others: the answer says how many were done and why the rest were not. Fixes need `reservations.manage` as well as `night-audit.run`.

An exception stops being listed when someone resolves it. Marking an exception resolved comes with the exceptions item, so a carried row stays until then.

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

| Route                                                 | Purpose                                                                                                                                                   |
| ----------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /` (`show`)                                      | The sheet data.                                                                                                                                           |
| `POST run`                                            | Queue `RunNightAuditJob` for the current user. Body: `force`, `reason`. A blocker with no force gives 422. `force` without `night-audit.force` gives 422. |
| `POST reservations/{reservation}/units/{unit}/assign` | `AutoAssignRooms`. Needs `reservations.manage`. 422 with a message when nothing is free.                                                                  |

## Lessons from wastays

7 pre-close and 8 post-close blocking checks, most needing a person at night.
