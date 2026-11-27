# Remarks

## Purpose

Notes for the teams that act on them: housekeeping ("Please set up swan towel"), the kitchen ("Allergic to prawn"), tours ("TK01 - Sandakan Nature Tours") and the cashier ("Room + BBF paid, collect TTx"). Tanini's arrival report has these four columns (requirement 7.1). They sit apart from the one free text **Notes** (`reservations.notes`).

**Status: built** (2026-11-22).

## Decisions

| Decision   | Rule                                                                                                                        |
| ---------- | --------------------------------------------------------------------------------------------------------------------------- |
| Kinds      | `RemarkKind`: Housekeeping, Dietary, Package, Payment. A new kind is an enum case and a CHECK change, not a new column.     |
| Level      | Per reservation, not per unit, as in Tanini's report. At most one remark of each kind.                                      |
| Length     | Up to 500 characters each. Trimmed; an empty box saves no row.                                                              |
| Where set  | The booking form **Notes** tab (under Notes), and **More → Edit remarks** on the reservation page. Closed reservations too. |
| Log        | `reservation.remarks_updated` names the kinds that changed, never the text: a dietary remark can be health information.     |
| Permission | `reservations.manage`.                                                                                                      |

## Data model

```
reservation_remarks  id, property_id (FK cascade), reservation_id (FK cascade), kind varchar(20), body varchar(500), timestamps
                     unique (reservation_id, kind), CHECK kind IN ('housekeeping', 'dietary', 'package', 'payment')
```

## Code

| Part                                                   | Role                                                                                     |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| `ValidatesRemarks` (Requests/Concerns)                 | The `remarks` object (`remarks.dietary`...) and `remarks()`: the trimmed ones with text. |
| `CreateReservation`                                    | Saves one row per remark. `reservation.created` lists the kinds.                         |
| `SaveReservationRemarks` (Action)                      | Adds, changes and removes to match the form. A save that changes nothing is not logged.  |
| `PUT /p/{property}/reservations/{reservation}/remarks` | `reservations.remarks.update`.                                                           |
| `RemarksFields`, `EditRemarksDialog`                   | One text box per kind (labels from `RemarkKind::options()`), and the Dialog.             |

## Where it shows

- Reservation page, summary card: a **Remarks** box with each remark under its label.
- Arrivals report: one column per kind (hidden by default). See [Arrivals columns](../22-reports/01-core-reports.md#arrivals-columns).
