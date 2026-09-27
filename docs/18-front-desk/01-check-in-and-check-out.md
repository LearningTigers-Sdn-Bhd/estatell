# Check-in and check-out

## Purpose

Move a unit through its stay.

## Actions

- `CheckInUnit`: requires an assigned room or bed for tonight, enforces the registration card scope, posts undated add-ons, sets `checked_in_at` and `UnitStatus::CheckedIn`.
- `CheckOutUnit`: requires the unit folio balance to be 0 or transferred to the master folio, sets `checked_out_at`, `UnitStatus::CheckedOut`, marks the room Dirty.
- `ProcessEarlyDeparture`: applies the rate plan's `early_departure_policy`.
- `ExtendStay`, `ChangeUnitDates`.
- `MarkNoShow`, `CancelUnit`.

## Rules

- Each transition is one Action, checked by `UnitStatus::canTransitionTo()`.
- Group actions ("check in all") loop over units.
- Credit exposure is checked at check-in for company-billed reservations.

## Permissions

`front-desk.check-in`, `front-desk.check-out`, `front-desk.check-in-without-card`.
