# Posting charges

## Purpose

Post room charges, extras and taxes correctly and exactly once.

## Actions

- `PostCharge`: manual charge from a charge item or open amount.
- `PostRoomCharges`: run by the night audit for each in-house unit night.
- `ReverseLine`: posts a reversal line, including reversals of child lines.
- `TransferLines`: moves lines between folios.

## Room charge pipeline (per unit night)

1. `InclusionAllocator` splits the night: ROOM RM250 + Breakfast RM50.
2. The room line posts at the full price. If `discount_amount` is set, a discount child line posts too.
3. `TaxCalculator` adds tax child lines.
4. `FolioRouter` chooses the target folio.
5. The idempotency key `night:{unit_night_id}` prevents double posting.

## Rules

- No forecast table. `unit_nights` already is the forecast.
- Surcharges from payment methods post as charge lines.

## Permissions

`folio.post-charge`, `folio.reverse`, `folio.transfer`.

## Lessons from wastays

`folio_forecasted_charges` plus repair services (`repair_completed_nightly_charges`, `resolve_missing_nightly_charges`) existed because posting was not idempotent.
