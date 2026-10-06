# Check-in and check-out

## Purpose

Move a unit through its stay.

## Actions

- `CheckInUnit`: requires an assigned room or bed for tonight, enforces the registration card scope, needs a known nationality when an enabled foreign-only tax exists (`nationality_required`), posts undated add-ons, sets `checked_in_at` and `UnitStatus::CheckedIn`.
- `CheckOutUnit`: requires the unit folio balance, **without the held security deposit**, to be 0 or transferred to the master folio. A held deposit must be refunded first, or the Action is refused as `deposit_unresolved`. It sets `checked_out_at`, `UnitStatus::CheckedOut` and marks the room Dirty.
- `ProcessEarlyDeparture`: applies the early departure [room revenue policy](../06-catalogues/06-room-revenue.md#policies) when a stay is shortened.
- `ExtendStay`, `ChangeUnitDates`.
- `ExtendDepartureTime`: late check-out on the departure date. Sets a later `departure_time` and posts the late check-out policy charge. Refused unless the unit is CheckedIn, on its departure date, and the new time is later and before midnight.
- `MarkNoShow`, `CancelUnit`: post the no-show or cancellation policy charge, unless staff choose **Do not charge**.
- `ReinstateUnit`: moves a Cancelled or NoShow unit back to Confirmed and re-prices it from `reservation_unit_children`.
- Each of these Actions calls `SyncReservationStatus` (module 12) after it changes a unit, so the reservation status follows its units. `ResolveMissedArrivals` in the night audit calls `MarkNoShow` for each unit its policy marks.

## Rules

- Each transition is one Action, checked by `UnitStatus::canTransitionTo()`.
- Group actions ("check in all") loop over units.
- Credit exposure is checked at check-in for company-billed reservations.

## Taxes and deposit

- **Nationality** for a foreign-only tax is the unit's primary guest, or the booker when the unit has no primary guest. Check-in shows a Nationality field when it is missing and saves it to the guest profile.
- **Taxes stay on the room revenue line.** Check-in posts no tax. Each night's tax is a child line of the room line (see [Posting charges](../13-folio/02-posting-charges.md)). A tax with **Collect at check-in** ([Taxes](../06-catalogues/03-taxes.md)) only changes what the guest is asked to pay at check-in.
- **Security deposit** is a payment with `PaymentPurpose::SecurityDeposit` on the unit's own folio. The default amount is `property_settings.security_deposit_amount`.
- **Held deposit** ([Payments](../14-payments/01-payments-and-refunds.md)) is excluded from the check-out balance rule. The check-out form refunds it with `RefundPayment`. To keep part of it for damages, staff post a charge on the folio first, then refund the rest.

## Permissions

`front-desk.check-in`, `front-desk.check-out`, `front-desk.check-in-without-card`, `front-desk.waive-fees`.

## Build order

One checklist item per group, in this order. The Actions come first and the reservation page forms follow.

1. `CheckInUnit` and `CheckOutUnit`, with the three permissions.
2. The room revenue policies, then `CancelUnit`, `MarkNoShow` and `ReinstateUnit`.
3. `ExtendStay`, `ChangeUnitDates`, `ProcessEarlyDeparture` and `ExtendDepartureTime`.
4. The unit **⋯** menu on the [reservation page](../12-reservations/06-reservation-page.md#units-tab), with the forms below.

## Forms on the reservation page

Each form uses `FormOverlay` and the container from [Form containers](../design-system/05-form-containers.md).

| Action                                         | Container   | Fields and notes                                                                                                                      |
| ---------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Check in                                       | Dialog      | Shows the room, the registration card status, a card override (permission), Nationality when missing, and [Collect now](#collect-now) |
| Check out                                      | Dialog      | Shows the folio balance and the held deposit. Settle or transfer the balance, and refund the deposit                                  |
| Cancel unit, Mark no-show                      | Dialog      | Shows the policy fee ("No charge" inside the free window), Do not charge, a reason                                                    |
| Reinstate                                      | AlertDialog | Re-prices the unit. Shows the new total                                                                                               |
| Change dates (also Extend and Early departure) | Dialog      | Dates and a live price difference. A shorter stay shows the early departure policy result                                             |
| Late check-out                                 | Dialog      | New time, the policy fee, Do not charge, a reason                                                                                     |

**Do not charge** means the fee is not posted to the folio. It shows only for staff with `front-desk.waive-fees` (the code keeps the word "waive"). The reason is required and the choice is logged with the user. The fee is read-only text from the server.

## How the forms work

1. The unit **⋯** menu lists only the actions that `UnitStatus::canTransitionTo()` and the staff member's permission allow. The server sends the list.
2. The action opens its form in `FormOverlay`. Data the form needs (the fee, the price difference, free rooms) loads when the form opens, through a read-only route that changes nothing.
3. The form sends only choices: dates, a room, **Do not charge** and a reason. It never sends an amount. The server calculates the fee and the price again on submit.
4. Success closes the form with a toast. A refusal stays open and shows on the matching field, or as a form alert, through `ReservationRefusals`.
5. Each Action writes an activity entry with ids and the reason (for example `reservation.unit_cancelled`) and calls `SyncReservationStatus`.

- The cancellation window and "on the departure date" use the property business date, not the system date (to verify against `CurrentBusinessDate`).
- Cancel, no-show, reinstate, change dates and move room need `reservations.manage`. Check in and check out need their own permission. Late check-out needs `front-desk.check-out`.
- If the policy changes while a form is open and the fee differs on submit, the server refuses with `fee_changed` and the form shows the new amount for staff to confirm again.
- A fee posts through the folio routing rules. When the unit has no open folio, the Action creates it, as `RecordPayment` does for a deposit (to verify against `PostFolioCharge`).
- Reinstating a unit keeps a posted fee. The Reinstate form shows a note, and the fee is corrected with Reverse on the folio.
- A group "Check in all" lists one row per unit in the same Dialog. A failed unit does not stop the others, and each failure shows on its own row. It takes no payment: staff collect per unit afterwards.

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
│ The room is released. Scheduled nights and      │
│ add-ons are removed.                            │
│                  [Keep unit] [Cancel unit]      │
└─────────────────────────────────────────────────┘
```

Mark no-show has the same layout, with the no-show policy and no free-until line.

### Change dates

```
┌ Change dates · Deluxe King ─────────────────────┐
│ Arrival    [ 12 Oct 2026 ]  (locked once in-house)
│ Departure  [ 14 Oct 2026 ]  2 nights (-1)       │
│ ┌ Price ─────────────────────────────────────┐  │
│ │ Was RM 840.00     Now RM 560.00            │  │
│ │ Difference                    - RM 280.00  │  │
│ └────────────────────────────────────────────┘  │
│ Shorter stay · early departure policy           │  Only when the stay is shorter
│ Unused night 14 Oct   RM 280.00 + tax           │
│ ☐ Do not charge   Reason [ ____________ ]       │
│ Room 101 is free for the new nights  ✓          │
│                    [Cancel] [Save dates]        │
└─────────────────────────────────────────────────┘
```

An in-house unit can change only the departure date. Extend stay opens this form with Arrival locked.

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

A section of the check-in Dialog, shown only with `payment.record`. The form sends choices only: the two checkboxes, the method and the reference. The server calculates every amount.

```
┌ Check in · Deluxe King ──────────────────── ✕ ┐
│ Lim Wei Jie · 12 → 15 Oct · 3 nights          │
│ Nationality   [ Singapore ⌄ ]                 │  only when missing
│                                               │
│ Collected at check-in                         │
│   Tourism tax        RM10 × 3     RM 30.00    │
│   International tax  RM3 × 3      RM  9.00    │
│   Taxes                           RM 39.00    │
│                                               │
│ ☑ Security deposit                RM 100.00   │  only when the property sets an amount
│                                               │
│ ☑ Collect now        Total RM 139.00          │
│ Method [ Cash ⌄ ]   Reference [ ____ ]        │  reference for Card, Transfer, E-wallet
│                    [Cancel] [Check in]        │
└───────────────────────────────────────────────┘
```

- The tax rows sum the Scheduled tax rows of every tax with **Collect at check-in** for the unit's nights, one row per tax. A foreign-only tax for a guest it does not apply to shows "Exempt" and adds 0. With no flagged tax, the tax rows are hidden.
- The amount is read-only. A guest who pays only part unticks **Collect now** and uses Record payment on the folio.
- The deposit box is ticked by default. It shows only when `security_deposit_amount` is above 0.
- With Collect now ticked, the server runs `CheckInUnit`, then `RecordPayment` for the taxes (Advance), then `RecordPayment` for the deposit (SecurityDeposit), with the same method and reference. A payment refusal shows on the method or reference field and leaves the unit checked in. A payment that already succeeded stays.
- When the room charges route to a Master folio, the tax rows are hidden. The deposit still posts to the unit folio.
- The Dialog counts five fields at most: Nationality, the deposit box, Collect now, Method and Reference.

### Check out

```
┌ Check out · Deluxe King ─────────────────── ✕ ┐
│ Tan Wei Ming · room 101 · departs today       │
│                                               │
│ Unit folio      RM 0.00   settled ✓           │
│ Deposit held    RM 100.00                     │
│                                               │
│ ( ) Refund deposit    RM [ 100.00 ]   Cash    │
│ ( ) Keep for damages  → post a charge first   │
│                    [Cancel] [Check out]       │
└───────────────────────────────────────────────┘
```

**Keep for damages** links to the folio to post the charge. It does not open a second Dialog. Without a held deposit, the deposit rows are hidden.
