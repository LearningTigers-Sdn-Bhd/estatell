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

- **Rows:** one per reservation with ledger entries due in the period (`CommissionBookings`), newest due first, 25 to a page. A booking shows once it has ended. A refund recorded a month later shows in that later period too, because rows follow due dates like vouchers.
- **Columns:** Property, Booking, Source, Booked, Check-in, Check-out, Guest, Value, one commission column per `CommissionService` (positive), Outstanding to hotel (signed: negative means the property owes Estatell).
- **Value:** what Estatell collected for a booking engine booking; otherwise the commission base, the room revenue before tax (reversals cancel out).
- **Filters in the URL:** period (day, week from Monday, month, quarter or year) around a date, property, service and a search on the booking reference or the booker's name. The default is this month in Malaysia time.
- **Footer:** totals of every row the filters keep, not only the page.
- **Header cards** (`CommissionTotals`) are figures as of now. They follow the property filter and ignore the period. Amounts are in ringgit, the currency Curlec settles in.
    - **Total cash liability:** each property's positive balance of entries not in a paid voucher, plus money held for booking engine bookings not yet in the ledger (`OnlineBookingMoney::heldOutsideLedger`).
    - **Immediate payable:** the open Draft and Approved vouchers with the earliest payout date. With none, a forecast of the coming cut-off from unvouchered entries. Only properties Estatell pays count.
    - **Owed to Estatell:** each property's negative balance, shown positive, with the number of properties.
- **Export CSV:** every filtered row with the page columns, `commissions-{from}-to-{to}.csv`.
- A row does not open the reservation yet: admins see staff pages only through view as.

## Vouchers tab

`/admin/commissions/vouchers` (Index), the second tab. The **Commissions** sidebar link opens the Bookings tab.

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

- **Approve** refuses a voucher that is no longer a Draft, and a positive voucher when the property has no active bank account (the page shows a warning). A voucher the property owes is approved without one. Approving saves the bank account and the totals by type and service in `snapshot`.
- **Bank account:** before approval the page shows the property's first active account; after, the saved one. Admins see the full number, because they make the transfer. The PDF and the property's own page show only the last 4 digits.
- **Mark as paid** needs an Approved voucher, a transfer date that is not in the future, and the bank reference.
- **Add adjustment** works on a Draft only. The amount is signed from the property's side, a reason is required, and the entry is linked to the voucher at once with the cycle's end as its due date. The form's key makes a repeated submit add nothing.
- Every action is logged, and every write route needs two-factor.
- **PDF:** `pdf.payout-voucher` through `PrintedPdf`, opened from `/admin/commissions/vouchers/{voucher}/pdf`. It is Estatell's document to the property, so it has no property letterhead.

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
└──────────────────────────────────────────────────────────────────────────────┘
```

- The table lists every rate, newest first per service, so it is also the history. Status is worked out against today: Scheduled, Current or Past.
- **Change rate** opens a Dialog with the service, the rate (0 to 100, up to 2 decimals) and the start date. A past date, or a date the service already has, is refused. The change is logged.
- Which properties are charged, and from when, is set per property on the [property Commissions tab](#property-commissions-tab).
- Saving needs two-factor, like every admin write.

## Property Commissions tab

A **Commissions** tab on the admin property page, `/admin/properties/{code}/commissions`, after Channels.

```
┌ Commissions ───────────────────────────────────────────────┐
│ Service          Rate    Charged                           │
│ Booking engine   10.00%  Since 1 Dec 2026          [Stop]  │
│ Channel manager   3.00%  Not charged               [Start] │
├ History ───────────────────────────────────────────────────┤
│ Service          From          To            By            │
│ Booking engine   1 Dec 2026    Open          Aina          │
└────────────────────────────────────────────────────────────┘
```

- One row per service with today's platform rate and its running or next period: **Not charged**, **From** (not started), **Since** (running) or **Until** (stopped later).
- The button follows the period: **Start** with no period or after a stop, **Stop** on a running open period, **Cancel** on a period that has not started.
- Start and Stop open a Dialog with one date, today or later. Cancel asks to confirm.
- **History** lists every period, newest first, with who started it.
- Rules: [Charging periods](02-commission-rules.md#charging-periods). Writes need two-factor.

## Access

Platform admins only, like the other admin pages. Properties never see these pages.
