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

A **Commissions** tab on platform settings, `/admin/settings/commissions`, next to General, Channel manager and File storage.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Platform settings                                                            │
│ [General] [Channel manager] [File storage] [% Commissions]                   │
├──────────────────────────────────────────────────────────────────────────────┤
│ Commission rates                                          [+ Change rate]    │
│ One rate per service for every property, on the amount before tax.           │
│ ┌──────────────────┬────────┬──────────────┬────────────┐                    │
│ │Service           │  Rate  │From          │Status      │                    │
│ ├──────────────────┼────────┼──────────────┼────────────┤                    │
│ │Booking engine    │  8.00% │01 Dec 2026   │Scheduled   │                    │
│ │Booking engine    │ 10.00% │01 Jan 2026   │Current     │                    │
│ │Channel manager   │  3.00% │01 Jan 2026   │Current     │                    │
│ └──────────────────┴────────┴──────────────┴────────────┘                    │
│                                                                              │
│ Channel manager commission start                                             │
│ Only bookings that check out on or after this date pay CM commission.        │
│ Start date [Not set ▾]                                       [Save changes]  │
└──────────────────────────────────────────────────────────────────────────────┘
```

- The table lists every rate, newest first per service, so it is also the history. Status is worked out against today: Scheduled, Current or Past.
- **Change rate** opens a Dialog with the service, the rate (0 to 100, up to 2 decimals) and the start date. A past date, or a date the service already has, is refused. The change is logged.
- **Channel manager commission start** is empty until an admin sets it. While empty, no channel manager commission is charged. A past date is refused; saving it is logged like the other platform settings.
- Saving needs two-factor, like every admin write.

## Access

Platform admins only, like the other admin pages. Properties never see these pages.
