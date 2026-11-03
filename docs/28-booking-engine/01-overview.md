# Booking engine overview

## Purpose

Let guests book and pay for a room online, on a property's microsite or on the owner's own website. estatell stays the source of truth for rooms, prices and money.

**Status: in build.** Planned on 2026-11-03. The feature flag and the General tab are built. Build order is in the [checklist](../checklist.md#28-booking-engine).

## Decisions

| Area                   | Decision                                                                                                   |
| ---------------------- | ---------------------------------------------------------------------------------------------------------- |
| Modes                  | Microsite and API only, both in the first release. A property uses one mode at a time, set by an admin     |
| Feature flag           | `BookingEngine`, per property, off by default, set by admins                                               |
| Microsite address      | `booking.estatell.com/{property-slug}`                                                                     |
| Microsite design       | A template with replaceable images, or a custom design by quotation                                        |
| Microsite stack        | Astro with React parts, one Node app on Coolify, in its own repo `estatell-booking-microsite`              |
| Payment                | Full payment at booking. estatell collects through Razorpay Curlec in both modes                           |
| Curlec account         | Reuse the existing account, with new API keys                                                              |
| Room hold              | 20 minutes                                                                                                 |
| Booking window         | At most 6 months ahead, because Curlec's normal refunds fail on older payments                             |
| Paid after hold expiry | Confirm if the room is still free; otherwise refund automatically and alert staff                          |
| Guests                 | Always a new guest record. Staff merge duplicates                                                          |
| Booking source         | A system source named "Booking Engine", in both modes                                                      |
| Cancel and refund      | Guest cancellations refund automatically. Staff are notified. No new module                                |
| Refund cost            | The property pays Curlec's kept fee and the refund fee, from its next payout                               |
| API test mode          | `demo.estatell.com` with a fixed test token and Curlec test keys                                           |
| Publishing             | Anyone with the permission, including admins through view as                                               |
| Channex amenities      | No sync in the first release                                                                               |
| new-wastays            | WIP. The API provides its endpoints, but the app is not built                                              |
| Commission fees        | A separate module, [planned later](../checklist.md#wip-not-planned-yet). It reads what this module records |
| Landing page wording   | Stays as it is                                                                                             |

## How it fits together

```
 Guest or Google                 Guest or Google
       │                               │
       ▼                               ▼
 Microsite (Astro)               Owner's website          new-wastays (WIP)
 booking.estatell.com/{slug}     (API only mode)                 ┆
       │                               │                         ┆
       └───────────► estatell API ◄────┘◄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┘
                          │
           ┌──────────────┼───────────────┐
           ▼              ▼               ▼
      Rooms, rates,   Reservations,    Razorpay Curlec
      availability    folios           (payment, refund)
                          │
                          ▼
             Channel sync and Commission fees
```

## Features

| #   | Feature               | What it does                                                          | Doc                                            |
| --- | --------------------- | --------------------------------------------------------------------- | ---------------------------------------------- |
| 1   | Property content      | Shows photos, amenities, nearby places, a map and policies            | [Content registries](06-content-registries.md) |
| 2   | Search and offers     | Shows available rooms and prices                                      | [API](02-api.md)                               |
| 3   | Checkout and hold     | Collects guest details and holds the room for 20 minutes              | [Checkout and hold](03-checkout-and-hold.md)   |
| 4   | Payment               | Guest pays in full on Curlec's checkout                               | [Payment](04-payment.md)                       |
| 5   | Confirmation          | Creates the reservation, folio and payment line, and emails the guest | [Payment](04-payment.md#confirmation)          |
| 6   | Manage booking        | Guest views or cancels; refunds go back through Curlec                | [Cancel and refund](05-cancel-and-refund.md)   |
| 7   | Engine settings       | Sets content, public rates and the options for the mode               | [Settings](07-settings-and-permissions.md)     |
| 8   | API access            | Gives API only properties a token, return addresses and docs          | [API only mode](09-api-only-mode.md)           |
| 9   | Template registry     | Lets admins release standard templates and assign custom designs      | [Microsite](08-microsite.md#template-registry) |
| 10  | Amenity registry      | Lets admins manage the property and room amenity lists                | [Content registries](06-content-registries.md) |
| 11  | Nearby place registry | Reuses places across properties, and lets admins review them          | [Content registries](06-content-registries.md) |

## Feature flag

- Pennant feature `BookingEngine` (`booking-engine`), off by default, managed from the admin property page like the other features.
- When off: Settings › Booking website returns 404, the microsite shows its real 404 page, and the API refuses the property.
- A booking already made keeps working when the flag goes off: its Manage booking link opens, and the reservation stays in the PMS. A guest who has paid can always view or cancel.

## What goes where

The booking engine is a guest entry point. It reuses other modules through their Actions and Services.

| Need                 | Used from                                                                                        |
| -------------------- | ------------------------------------------------------------------------------------------------ |
| Availability         | `AvailabilityService`, which also counts [holds](03-checkout-and-hold.md#holds-and-availability) |
| Prices and tax       | `BookingQuote`, `RateQuoteService`, `RestrictionCheck`                                           |
| Creating the booking | `CreateBooking`, as the company portal does                                                      |
| Cancelling           | `CancelUnits` and `PolicyChargeCalculator`                                                       |
| Money on the folio   | `RecordPayment`, `RefundPayment`                                                                 |
| Channel sync         | The existing ARI sync, when availability changes                                                 |

## Lessons from wastays

The wastays engine was about 1,500 lines of services and jobs, with two gateway adapters.

| Keep                                         | Avoid                                                                   |
| -------------------------------------------- | ----------------------------------------------------------------------- |
| Quote, hold, pay, confirm                    | Holds that subtract from a stored inventory table                       |
| The `razorpay` flow: order, signature, fetch | The `curlec` adapter and `/mock_payment`, which confirm with no payment |
| Unique indexes on gateway references         | jsonb snapshots on quotes                                               |
| Recording who collected the money            | Treating `authorized` as paid                                           |
| The Google Maps link parser                  | Guest IC and passport numbers in gateway order notes                    |

## To verify before real money is collected

| Topic         | What is left                                                                                     |
| ------------- | ------------------------------------------------------------------------------------------------ |
| Curlec        | The Route rate on our plan, linked account documents and the tax on fees ("18% GST" on its page) |
| Legal and tax | BNM licensing for collecting money for properties, SST and e-invoicing on commission, PDPA roles |

## Later

Google free booking links, per-amenity details, draft preview of a site, and refund or payment changes that Curlec's answers make possible.
