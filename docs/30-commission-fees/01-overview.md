# Commission fees overview

## Purpose

Track what Estatell earns from each property's bookings, hold the money Estatell collects for properties, and pay it out on fixed dates. Platform admins see every property; each property sees its own bookings and payouts.

**Status: built.** Planned on 2026-11-11. Rates, the ledger, the Commissions settings tab, booking engine and channel manager recording, payout vouchers, the platform Commissions page, charging periods per property and the property Payouts page are built. Build order is in the [checklist](../checklist.md#30-commission-fees).

## Decisions

| Area                     | Decision                                                                                              |
| ------------------------ | ----------------------------------------------------------------------------------------------------- |
| Services in this release | Booking engine (10%) and channel manager (3%)                                                         |
| Commission base          | The amount before tax                                                                                 |
| Turning a service on     | A charging period per property and service, started and stopped by admins. No period, no commission   |
| Rates                    | One platform rate per service, with history. No rate per property in this release                     |
| Rate change              | Applies to bookings made on or after the change date                                                  |
| Curlec's normal fee      | Estatell pays it from its commission                                                                  |
| Curlec's refund cost     | The property pays the fee Curlec keeps and the RM 0.50 refund fee, from its next payout               |
| Cut-off and payout       | Cut-off on the 15th, payout on the 1st (the Monday if it is a weekend), by due date                   |
| Paid voucher             | Never changes. Later corrections go into the next voucher as reversals                                |
| Negative balance         | Carried forward until it reaches RM 50 or 3 months; then the property pays by bank transfer           |
| Payout method            | Manual bank transfer by a platform admin. Curlec Route waits for Curlec's answers                     |
| Who approves and pays    | Platform admins only                                                                                  |
| Property access          | `payouts.view`, given to Owner by default. Shown while a service is on or a non-Draft voucher exists  |
| Reuse                    | Each service is one enum case and one rule class. The ledger, vouchers and pages work for any service |

## Money flow

| Service         | Who receives the guest's money | How Estatell gets its commission                                                      |
| --------------- | ------------------------------ | ------------------------------------------------------------------------------------- |
| Booking engine  | Estatell, through Curlec       | Kept from the money before the payout                                                 |
| Channel manager | The property (OTA or guest)    | Taken from the property's booking engine payout; otherwise the property pays Estatell |

Example: a guest pays RM 540 on the microsite, with a room total of RM 450 before tax. Estatell keeps RM 45 and pays the property RM 495 on the 1st.

Money that Estatell holds for properties is a liability, not income. Only commission entries are Estatell's income.

## How it fits together

```
 Booking engine                 Channels                 Front desk
 (Curlec payment, refund)       (Channex bookings)       (check-out, cancel, no-show)
        │                             │                         │
        └──────────── events ─────────┴─────────────────────────┘
                              │
                              ▼
                 Commission rules (one per service)
                              │
                              ▼
                    commission_entries (ledger)
                              │
                 on the 16th  ▼
                       payout_vouchers
                     ┌────────┴────────┐
                     ▼                 ▼
          Admin: Commissions     Property: Payouts
```

## Features

| #   | Feature          | What it does                                                        | Doc                                        |
| --- | ---------------- | ------------------------------------------------------------------- | ------------------------------------------ |
| 1   | Commission rules | Decides which bookings pay commission, on what amount and when      | [Commission rules](02-commission-rules.md) |
| 2   | Ledger           | Records money collected, refunds, fees and commission, never edited | [Ledger](03-ledger.md)                     |
| 3   | Payout vouchers  | Groups each property's entries by cycle, then approve and pay       | [Payout vouchers](04-payout-vouchers.md)   |
| 4   | Platform pages   | Admin dashboard, vouchers, rates and each property's periods        | [Platform pages](05-platform-pages.md)     |
| 5   | Property payouts | The property's own bookings, next payout and past payouts           | [Property payouts](06-property-payouts.md) |

## What goes where

The module reads other modules through their events, Actions and Services. It never writes to their tables.

| Need                         | Used from                                                                             |
| ---------------------------- | ------------------------------------------------------------------------------------- |
| Money collected              | `OnlineBookingMoney` (booking engine), over `gateway_payments`                        |
| Refunds and their cost       | `gateway_refunds` (payments)                                                          |
| Which service a booking used | `booking_sources` (Booking Engine source), `channel_booking_revisions`                |
| Commission base              | `ReservationRoomRevenue` (Folio); the booking engine caps it at the room total booked |
| When a booking ends          | The hourly `RecordDueCommissions` run: every unit checked out, cancelled or no-show   |
| Is the service on            | The Pennant features                                                                  |
| Voucher number               | `PV-{YYYYMM}-{property code}`, no sequence                                            |
| Where to pay                 | `property_bank_accounts`                                                              |

Code lives in `app/Actions/Commissions` and `app/Services/Commissions`. An hourly run finds ended bookings, because no single event marks the end of a booking.

## Later

| Topic              | Note                                                                                                                                       |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Self check-in (5%) | Kiosk-free self check-in only; the kiosk is out of scope. Comes when the team builds kiosk-free and the property has the concierge page on |
| new-wastays        | Its rate is not decided                                                                                                                    |
| Rate per property  | Not in this release                                                                                                                        |
| Curlec Route       | Automatic payouts, after Curlec answers our questions                                                                                      |

## To verify before real money is collected

SST and e-invoicing on commission, BNM licensing for holding money for properties, and PDPA roles. These are parked by the user and must be answered first. The ledger can take a tax line later without a redesign.
