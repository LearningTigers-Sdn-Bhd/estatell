# Platform pages

## Purpose

Let platform admins track money held for properties, commission per service, and payouts, and approve and pay vouchers. Sidebar: **Commissions** in the admin area.

## Bookings tab

`/admin/commissions` (Index)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Commissions                                                     [Export CSV] │
│ [▤ Bookings] [▧ Vouchers]                                                    │
├──────────────────────────────────────────────────────────────────────────────┤
│ ┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────┐   │
│ │ Total cash liability │ │ Immediate payable    │ │ Owed to Estatell     │   │
│ │ RM 48,210.00         │ │ RM 12,940.00         │ │ RM 1,320.00          │   │
│ │ held, not paid out   │ │ due 1 Dec 2026       │ │ 6 properties         │   │
│ └──────────────────────┘ └──────────────────────┘ └──────────────────────┘   │
│                                                                              │
│ [Day|Week|Month|Quarter|Year] [◀ Nov 2026 ▶] [Property ▾] [Service ▾] [Search]│
│ ┌─────────┬───────┬────────┬────────┬────────┬───────┬───────┬─────┬────┬────┐│
│ │Property │Booking│Source  │Booked  │Check-in│Check- │Guest  │Value│ BE │ CM ││
│ │         │       │        │        │        │out    │       │     │    │    ││
│ ├─────────┼───────┼────────┼────────┼────────┼───────┼───────┼─────┼────┼────┤│
│ │Sand Bay │SB-1042│Booking │3 Nov   │12 Nov  │14 Nov │A. Lim │540.0│45.0│ –  ││
│ │         │       │Engine  │10:24   │        │       │       │     │    │    ││
│ │Sand Bay │SB-1047│Agoda   │5 Nov   │13 Nov  │15 Nov │R. Tan │380.0│ –  │10.7││
│ └─────────┴───────┴────────┴────────┴────────┴───────┴───────┴─────┴────┴────┘│
└──────────────────────────────────────────────────────────────────────────────┘
```

- One row per reservation with at least one commission service. The row opens the reservation through view as.
- Columns follow the requirements sheet: Property, Booking number, Source, Booked at, Check-in, Check-out, Guest, Total booking value, one column per `CommissionService`, Outstanding to hotel.
- **Total booking value** depends on the source: the guest's payment for the booking engine, the folio's room revenue before tax for the channel manager.
- The period filter uses the due date. Totals sit in the table footer. CSV export uses the report export.

## Vouchers tab

`/admin/commissions/vouchers` (Index)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Commissions                                                                  │
│ [▤ Bookings] [▧ Vouchers]                                                    │
├──────────────────────────────────────────────────────────────────────────────┤
│ [Cycle: 16 Oct – 15 Nov 2026 ▾]  [Status ▾]  [Property ▾]                    │
│ ┌────────────┬────────────┬────────────┬──────────────┬────────────┬───────┐ │
│ │Voucher     │Property    │Due on      │Amount        │Direction   │Status │ │
│ ├────────────┼────────────┼────────────┼──────────────┼────────────┼───────┤ │
│ │PV-2026-0011│Sand Bay    │1 Dec 2026  │RM 8,420.00   │To property │Draft  │ │
│ │PV-2026-0004│Hill Lodge  │1 Dec 2026  │RM -96.00     │To Estatell │Carried│ │
│ │PV-2026-0009│Bay Inn     │1 Nov 2026  │RM 3,010.00   │To property │Paid   │ │
│ └────────────┴────────────┴────────────┴──────────────┴────────────┴───────┘ │
└──────────────────────────────────────────────────────────────────────────────┘
```

## Voucher page

`/admin/commissions/vouchers/{voucher}` (Detail)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ ← Vouchers   PV-2026-0011 · Sand Bay Resort                                  │
│                        [Add adjustment] [Approve] [Mark as paid] [PDF]       │
├────────────────────────────────────────────────────┬─────────────────────────┤
│ Lines                                              │ Summary                 │
│ ┌──────────┬─────────┬──────────────┬───────────┐  │ Cycle  16 Oct – 15 Nov  │
│ │Due date  │Booking  │Type          │Amount     │  │ Due    1 Dec 2026       │
│ ├──────────┼─────────┼──────────────┼───────────┤  │ Status Draft            │
│ │14 Nov    │SB-1042  │Collected     │  +540.00  │  │─────────────────────────│
│ │14 Nov    │SB-1042  │BE commission │   -45.00  │  │ Collected     9,300.00  │
│ │15 Nov    │SB-1047  │CM commission │   -10.73  │  │ Refunded       -120.00  │
│ │10 Nov    │SB-1031  │Refunded      │  -120.00  │  │ Refund fees      -4.10  │
│ │10 Nov    │SB-1031  │Refund fee    │    -4.10  │  │ BE commission  -690.00  │
│ │—         │—        │Carried fwd   │   -60.00  │  │ CM commission   -25.90  │
│ └──────────┴─────────┴──────────────┴───────────┘  │ Carried         -60.00  │
│                                                    │ Payable       8,420.00  │
│                                                    │─────────────────────────│
│                                                    │ Pay to: Maybank         │
│                                                    │ SAND BAY RESORT SDN BHD │
│                                                    │ ••••4821                │
└────────────────────────────────────────────────────┴─────────────────────────┘
```

| Action         | Shown when | Form                                          |
| -------------- | ---------- | --------------------------------------------- |
| Add adjustment | Draft      | Dialog: amount (signed) and a required reason |
| Approve        | Draft      | Confirm                                       |
| Mark as paid   | Approved   | Dialog: transfer date and bank reference      |
| PDF            | Always     | Opens the PDF                                 |

## Commission rates

A **Commission rates** section on `/admin/settings` (Settings archetype, one card).

```
┌ Commission rates ────────────────────────────────────────────────────────────┐
│ Service           Rate     From                                              │
│ Booking engine    10.00%   1 Dec 2026                       [Change rate]    │
│ Channel manager    3.00%   1 Dec 2026                       [Change rate]    │
│ Channel manager commission from: 1 Dec 2026                 [Change]         │
│                                                             [View history]   │
└──────────────────────────────────────────────────────────────────────────────┘
```

Change rate opens a Dialog with the new rate and its start date. A start date in the past is refused.

## Access

Platform admins only, like the other admin pages. Properties never see these pages.
