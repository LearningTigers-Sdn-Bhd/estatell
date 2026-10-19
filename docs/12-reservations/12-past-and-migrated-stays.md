# Past and migrated stays

## Purpose

Enter a stay that began before today. Two cases:

- **Past stay**: the stay started on or after the property's first business date and was entered late (a walk-in typed in the next morning, a paper booking). estatell charges every night, with its real date.
- **Migrated stay**: the stay started before the property's first business date, in the old PMS. estatell does not charge the old nights. It carries the old folio over (see [Old folio](#old-folio)).

Normal bookings still refuse an arrival before the business date (`arrival_in_past`). These two modes are the only staff paths around it.

**Go-live date** in this file means the property's first business date (the earliest `business_dates.date`). It is usually the day the property went live. An admin can set it earlier when the owner asked to replay past stays day by day ([Go live](../17-onboarding/03-go-live.md#actions)). That path stays as it is.

## Booking form modes

The booking form's **Reservation mode** select gains two options, shown only to users with `folio.manual`:

| Mode          | Shown                                                                                                     | URL                                   | Name                    |
| ------------- | --------------------------------------------------------------------------------------------------------- | ------------------------------------- | ----------------------- |
| New booking   | Always                                                                                                    | `GET /p/{code}/reservations/new`      | `reservations.new`      |
| Walk-in       | Always                                                                                                    | `GET /p/{code}/reservations/walk-in`  | `reservations.walk-in`  |
| Past stay     | With `folio.manual`                                                                                       | `GET /p/{code}/reservations/past`     | `reservations.past`     |
| Migrated stay | With `folio.manual`, and inside the [migration window](../17-onboarding/07-migration.md#migration-window) | `GET /p/{code}/reservations/migrated` | `reservations.migrated` |

Both new modes post to `POST /p/{code}/reservations/past` (`reservations.store-past`, `reservations.manage` and `folio.manual`). Switching modes keeps what was typed, like New booking and Walk-in.

## Past stay

```
┌─ New booking ───────────────────────────────────────────────────────────────────┐
│  Reservation mode [Past stay ▾]                                                 │
│  ┌ Past stay ─────────────────────────────────────────────────────────────────┐ │
│  │ The stay is entered after it began. Nights before 10 Oct post today with   │ │
│  │ their own dates.                                                           │ │
│  │ Status        (•) Still in house   ( ) Already checked out                 │ │
│  │ Checked in    [08 Oct ▾] [15:00]                                           │ │
│  │ Departure     [12 Oct ▾] [12:00]                                           │ │
│  │ Reason *      [e.g. Walk-in entered late                               ]   │ │
│  └────────────────────────────────────────────────────────────────────────────┘ │
│  Line 1   Standard Double · Room Only · 2 adults · Room [103 ▾]                 │
│                                        [Cancel]  [Save and open folio]          │
└─────────────────────────────────────────────────────────────────────────────────┘
```

| Field      | Rule                                                                                                                                                                                                              |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Status     | **Still in house**: the departure is after the business date. **Already checked out**: the departure is on or before the business date.                                                                           |
| Checked in | Before the business date and on or after the go-live date (`arrival_before_go_live`, "This stay began before go-live. Use Migrated stay."). Not the business date: that is a normal walk-in (`arrival_not_past`). |
| Rooms      | Every line has a room or bed for the whole stay, as on a Walk-in (the form has one pick for each line, not one for each night). A night that is not free in that room is refused (`room_not_free` on the night).  |
| Prices     | Quoted by `RateQuoteService` as today. The ✎ override works as on a normal booking. The booking summary on the right shows the nights as on any booking.                                                          |
| Reason     | Required.                                                                                                                                                                                                         |
| Source     | Any active source. Walk-in is the default.                                                                                                                                                                        |

Restrictions (min stay, closed to arrival) are not checked for the past nights. Availability is.

### Saving

`CreatePastStay` (`app/Actions/Reservations/`) runs the existing Actions in one transaction:

1. `CreateBooking` with `allowPastArrival` (new flag, only this Action passes it), so the arrival check and the restriction check are skipped and the room picks are required. Availability is still checked.
2. `CheckInUnit` for each unit, with `checked_in_at` set to the entered date and time through the new `asOf` input. The arrival, the end of the stay and tonight's room are checked on that day, not on the business date, so a stay that has already ended can still be checked in. The nationality check runs as at a normal check-in. The registration card rule is skipped for a user with `front-desk.check-in-without-card`, because no card can exist before the stay is saved.
3. `PostMissedNights` for each unit (below). `CheckInUnit` calls it itself, so `CreatePastStay` does not call it twice.
4. **Already checked out**: `CheckOutUnit` for each unit with `checked_out_at` set to the entered departure date and time (`asOf`), skipping the balance check and the closing of the folios (`skipBalanceCheck`, only this Action passes it). The folio stays **Open** with its balance. The room is not marked Dirty: the stay ended in the past.
5. Logs `reservation.past_stay_created` with the reason and the units.

Any refusal rolls everything back and names the line. On success:

- **Still in house** opens the folio tab.
- **Already checked out** opens the Folio tab on the first folio of the stay, with an alert dialog: "Settle the folio now?". **Settle now** opens the [manual folio page](../13-folio/08-manual-folio.md) on that folio, with the past stay reason as the page reason, so staff enter the payments and close the folio. **Later** (or Esc) closes the dialog and leaves the folio Open with its balance. The dialog shows once, for users with `folio.manual`. A departure after the business date is refused (`departure_after_today`, "Choose Still in house."). The form shows Status as a switch; changing it clears the departure date.

No policy runs: no no-show, cancellation, early departure or late check-out fee.

### `PostMissedNights`

`(?User $actor, ReservationUnit $unit): PostingResult` in `app/Actions/NightAudit/`. Posts every night of the unit that is before the business date, on or after the go-live date, and has no `night:{unit_night_id}` line.

- Each night goes through `PostRoomCharges` with that night as `$date`, the same path a [Retry](../20-night-audit/02-readiness-panel.md#retry-a-room-charge) uses. `posting_date` is the business date and `service_date` is the night.
- The key stays `night:{unit_night_id}`, so a rerun posts nothing twice and the next audit never charges the night again.
- Scheduled add-ons with a `service_date` before the business date post the same way (`addon:{id}`).
- One transaction with the unit locked. A refusal of any night posts nothing.

### Late arrival check-in

With **Keep as late arrival**, a unit due on the 8th that checks in on the 10th today gets only the nights from the 10th. `CheckInUnit` now calls `PostMissedNights` after the check-in, so the 8th and 9th post too, each with its own service date. This closes a gap: before this change those nights were never charged (a test showed it, then the fix closed it).

Staff who do not want to charge the missed nights reverse them on the folio, or use Change dates before check-in. No new choice is added to the check-in Dialog.

## Migrated stay

For a guest who is in the house on go-live day, or a stay that started before it. Shown only inside the migration window.

```
┌─ New booking ───────────────────────────────────────────────────────────────────┐
│  Reservation mode [Migrated stay ▾]                                             │
│  ┌ Migrated stay ─────────────────────────────────────────────────────────────┐ │
│  │ Checked in    [01 Oct ▾] [15:00]     Departure  [12 Oct ▾] [12:00]         │ │
│  │ Nights before go-live (1–9 Oct) are not charged here.                      │ │
│  │                                                                            │ │
│  │ Old folio   (•) One amount   [ 1,080.00 ]   Old folio no. [e.g. F1203   ]  │ │
│  │                 What the guest still owes in your old system.              │ │
│  │                 Enter a minus amount for a credit.                         │ │
│  │             ( ) Copy line by line   opens the old folio page after saving  │ │
│  │ Reason *    [e.g. Migrated from old PMS                                ]   │ │
│  └────────────────────────────────────────────────────────────────────────────┘ │
│  Night    Room     Price                                                        │
│  01 Oct   [103 ▾]  —        before go-live                                      │
│  ...                                                                            │
│  09 Oct   [103 ▾]  —        before go-live                                      │
│  10 Oct   [103 ▾]  250.00   tonight's audit                                     │
│                                        [Cancel]  [Save and open folio]          │
└─────────────────────────────────────────────────────────────────────────────────┘
```

| Field      | Rule                                                                                                                                                                                |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Checked in | Before the go-live date (`arrival_not_before_go_live`, "Use Past stay for a stay that began after go-live.").                                                                       |
| Departure  | After the business date: the guest is still in the house. A stay that ended before go-live is not entered (see [Migration](../17-onboarding/07-migration.md#what-is-not-migrated)). |
| Rooms      | Every night has a room or bed, and must be free, as for a Past stay. Nights before go-live keep their room, so room history and the police report are complete.                     |
| Prices     | Nights before go-live show "before go-live" and no price. Their `unit_nights` rows keep the quoted amount, which is never posted.                                                   |
| Old folio  | **One amount** (the default) or **Copy line by line**.                                                                                                                              |
| Reason     | Required.                                                                                                                                                                           |

`CreateMigratedStay` runs `CreateBooking` with `allowPastArrival`, `CheckInUnit` with `asOf`, then `PostOpeningBalance` (One amount) for the unit's folio, in one transaction. The form has one amount and one old folio number for the stay, so the opening line posts on the folio of the first unit (the one the success page opens). The form posts `mode=migrated` and `old_folio` (`one_amount` or `copy_line_by_line`) to `reservations.store-past`; an empty amount is zero and posts nothing. It logs `reservation.migrated_stay_created`. **Copy line by line** saves the stay with no opening line and opens the old folio page.

**A night before the go-live date is never charged.** This needs no column: `PostRoomCharges`, `PostMissedNights` and the audit refuse a night before the go-live date (`night_before_go_live`). The folio's Scheduled rows and the booking summary totals leave those nights out.

## Old folio

A folio carries its old-system part in one of two ways, never both. Switching from one to the other (inside the window) reverses the lines of the first.

### One amount

`PostOpeningBalance` (`app/Actions/Folio/`) posts one line with the system **Opening balance** code ([Migration](../17-onboarding/07-migration.md#opening-balance-code)):

- `posting_date` is the business date and `service_date` is the go-live date. The description is "Opening balance · old folio {number}". `folios.legacy_number` holds the number.
- The amount is signed: positive when the guest owes, negative for a credit. Zero posts nothing.
- No tax lines. The key is `opening:{folio_id}:{n}`.

```
│ Date     Description                              Amount     Balance │
│ 10 Oct   Opening balance · old folio F1203      1,080.00    1,080.00 │
│ 10 Oct   Room · Standard Double                   250.00    1,330.00 │
│ 10 Oct   SST 8%                                    20.00    1,350.00 │
```

**Change opening balance** is in the line's ⋯ menu (inside the window, `folio.manual`). A Dialog asks for the new amount and a reason. `ChangeOpeningBalance` reverses the current line and posts the new one in one transaction. Staff never see the reversal step.

A payment made in the old system is part of this amount. A payment received after go-live is a normal payment.

### Copy line by line

The **old folio page** (`GET /p/{code}/reservations/{reservation}/old-folio`, `reservations.old-folio`, `folio.manual`, inside the window) uses the same page shell as the [manual folio page](../13-folio/08-manual-folio.md#page). Its tabs are the reservation's folios instead of New and Posted lines.

```
┌─ Copy old folio · GRH-26100231 · Ahmad Faiz ───────────────────────────────────────┐
│  Lines before go-live (10 Oct). Enter them as your old folios show them.           │
│  [Room 103 · F1203]  [Master · F1204]  [+ Folio ▾]                                 │
│  Old folio no.  [F1203     ]                                                       │
│  Date     Item / method          Description          Qty   Amount                 │
│  01 Oct   Room night             Standard Double       1    [216.00]   🗑          │
│  ...                                                                               │
│  09 Oct   Room night             Standard Double       1    [216.00]   🗑          │
│  [01 Oct▾][SST              ▾]   [e.g. SST 8%     ]    ·    [155.52]   🗑          │
│  [04 Oct▾][Minibar          ▾]   [e.g. 2 x Coke   ]   [2]   [  6.00]   🗑          │
│  [01 Oct▾][Old system · Cash▾]   [e.g. OR-1203    ]    ·    [500.00]   🗑          │
│                                                              [+ Add line]          │
│  This folio   1,779.52        All folios   1,779.52                                │
│                                       [Discard]  [Save old folios]                 │
└────────────────────────────────────────────────────────────────────────────────────┘
```

- **Tabs**: one per folio. **+ Folio** adds a Master or Third-party folio with `CreateFolio` and the existing third-party payer picker. Each tab has its own **Old folio no.** (`folios.legacy_number`).
- **Rows**: a date before the go-live date, an item (a charge item, a tax, or a payment method), a description, a quantity and an amount. Room nights are drafted from the unit nights before go-live, one row per night, with the quoted price ready to edit. Staff can move a row to another tab before saving.
- **Tax is a row of its own**, copied from the old folio. estatell does not calculate it. It posts as a top-level Tax line, the only place a Tax line has no parent.
- **Payments** post through `RecordPayment` with the [Old system](../17-onboarding/07-migration.md#old-system-payment-method) method and the old receipt number as the reference.
- `SaveOldFolios` posts every tab in one transaction. Each line has `migrated` = true, its own `service_date` and the business date as `posting_date`. Keys: `migrated:{folio_id}:{uuid}`.
- **Editing later** inside the window: the page opens with the saved lines. A changed line becomes a reversal and a new line, a removed line a reversal. After the window the page is read only.

### On the folio tab

Copied lines sit in their own section above POSTED:

```
│ ▸ OLD SYSTEM · folio F1203                              1,279.52         │
│ POSTED                                                                    │
│   10 Oct   Room · Standard Double              1      250.00    1,529.52  │
```

- The **OLD SYSTEM** header is a button like SCHEDULED, with its total. It starts **collapsed**. It shows only when the folio has migrated lines.
- **Edit old folio** sits in the section header, inside the window, for `folio.manual`.
- Migrated lines can be moved with Move charges. A copy keeps `migrated`.
- The folio invoice and the voucher print the same section with its own subtotal ([Folio invoice](../13-folio/06-folio-invoice.md#content)).

## Rules

- Both modes need `folio.manual` (Owner, Manager).
- Neither mode writes into a closed business date. Every line posts on the business date. Only `service_date` goes back in time.
- A migrated stay is a normal reservation from then on: the night audit charges the nights from go-live, and check-out needs a balance of 0.

## Build order

| Item | Commit                                                                | Covers                                                                                                                                                                               |
| ---- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1    | `feat(reservations): add past stays still in house`                   | The Past stay mode and route, `CreatePastStay` (still in house), `allowPastArrival`, `CheckInUnit` `asOf`, `PostMissedNights`, the late-arrival catch-up                             |
| 2    | `feat(reservations): add past stays already checked out`              | Already checked out, `CheckOutUnit` `skipBalanceCheck`, opening the manual folio page. Needs: the manual folio page                                                                  |
| 3    | `feat(reservations): add migrated stays with one amount`              | The Migrated stay mode and route, `CreateMigratedStay`, `night_before_go_live`, `PostOpeningBalance`, `ChangeOpeningBalance`. Needs: the migration window                            |
| 4    | `feat(reservations): copy old folios line by line`                    | The old folio page, folio tabs, `SaveOldFolios`, `folio_lines.migrated`, top-level copied Tax lines. The first save only: saved lines are read only                                  |
| 5    | `feat(reservations): edit old folios and show the old system section` | Editing inside the window, switching between One amount and Copy line by line, the OLD SYSTEM section on the folio tab, the invoice and the voucher, Move charges keeping `migrated` |
