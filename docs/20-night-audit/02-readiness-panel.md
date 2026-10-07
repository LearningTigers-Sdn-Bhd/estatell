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

An item with no policy to settle it is a warning: the audit runs and the policy decides (see [Audit run](03-audit-run.md#steps)). Fixes with a form (check in, check out, extend, settle) stay on the reservation page, so the sheet links there instead of repeating the Dialogs. **Assign** is the only fix in place, because it needs no input.

An exception stops being listed when someone resolves it. Marking an exception resolved comes with the exceptions item, so a carried row stays until then.

## Not built

| Check                  | Why                                                                                                |
| ---------------------- | -------------------------------------------------------------------------------------------------- |
| Pending payments       | Nothing creates a Pending payment: `RecordPayment` completes at once. Add it with online payments. |
| Channel booking errors | Module 21.                                                                                         |
| Overbooked nights      | Only channel bookings can overbook. Module 21. `NightAvailability` already shows the count.        |
| Mark late arrival      | Staff set `late_arrival_expected` on the booking. A later fix can add an Action.                   |

## The gate

`RunNightAudit` refuses a run that would start the audit with `blocked_by_readiness` while a blocker is open, unless the run is forced (`night-audit.force` and a reason). A run that resumes a Failed audit is never blocked. A scheduled run never forces, so it stops at a blocker.

## The sheet

Opened by the **Run Night Audit** button in the Operations group (shown to users with `night-audit.run` on a Live property). The nav item has an `action` and the sheet loads its data from `GET /p/{code}/night-audit` as JSON (`night-audit.show`), so nothing loads until it opens.

| State     | What it shows                                                                                                              | Buttons                  |
| --------- | -------------------------------------------------------------------------------------------------------------------------- | ------------------------ |
| Ready     | "Closes 12 Oct and opens 13 Oct". **Fix before running** (blockers), **Needs a look** (warnings), or "Everything is ready" | Cancel, **Run audit**    |
| Blocked   | The same. **Run audit** is disabled. Users with `night-audit.force` see **Force close…**, which asks for a reason          | Cancel, **Force close**  |
| Running   | The six steps with their counts, refreshed every 3 seconds                                                                 | Close                    |
| Failed    | The step it stopped at. Finished steps are kept                                                                            | Cancel, **Resume audit** |
| Completed | "Now trading on 13 Oct" and the number of items that need a look                                                           | Done                     |

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
