# Property payouts

## Purpose

Let a property track the money Estatell collects for it, the commission per service, and its payouts. Sidebar: **Payouts** in the Finance area.

## Permission

- New permission `payouts.view` (`PermissionEnum::PayoutsView`).
- Given to the Owner role by default. The owner can give it to other roles.
- The page is shown when the property has a commission service on today, or has a voucher it may see. A property that stops every service still sees its past payouts. Otherwise the routes answer 404 (`EnsurePayoutsAreShown`) and the sidebar leaves the item out.
- Draft vouchers are hidden. The property sees Approved, Paid and Carried forward vouchers only (`PayoutVoucherStatus::isVisibleToProperty`). A Draft or another property's voucher answers 404.

## Bookings tab

`/p/{property:code}/finance/payouts` (Index)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Payouts                                                         [Export CSV] │
│ [▤ Bookings] [▧ Payouts]                                                     │
├──────────────────────────────────────────────────────────────────────────────┤
│ ┌──────────────────────────┐ ┌──────────────────────────────────────────┐    │
│ │ Next payout              │ │ Last payout                              │    │
│ │ RM 8,420.00              │ │ RM 3,010.00 · paid 1 Nov 2026            │    │
│ │ due 1 Dec 2026           │ │ ref MBB20261101-889                      │    │
│ └──────────────────────────┘ └──────────────────────────────────────────┘    │
│                                                                              │
│ [Day|Week|Month|Quarter|Year] [◀ Nov 2026 ▶] [Service ▾] [Search booking/guest]│
│ ┌───────┬────────┬────────┬────────┬────────┬────────┬───────┬─────┬─────┬───┐│
│ │Booking│Source  │Booked  │Check-in│Check-  │Guest   │Value  │ BE  │ CM  │Out││
│ │       │        │        │        │out     │        │       │     │     │   ││
│ ├───────┼────────┼────────┼────────┼────────┼────────┼───────┼─────┼─────┼───┤│
│ │SB-1042│Booking │3 Nov   │12 Nov  │14 Nov  │A. Lim  │540.00 │45.00│  –  │495││
│ │       │Engine  │10:24   │        │        │        │       │     │     │   ││
│ │SB-1047│Agoda   │5 Nov   │13 Nov  │15 Nov  │R. Tan  │380.00 │  –  │10.73│-11││
│ └───────┴────────┴────────┴────────┴────────┴────────┴───────┴─────┴─────┴───┘│
└──────────────────────────────────────────────────────────────────────────────┘
```

- The same columns, filters and footer as the admin Bookings tab, without Property (`CommissionBookings`, limited to the property). "Out" is Outstanding to hotel. Search matches the booking reference or the booker's name.
- **Next payout** (`PropertyPayouts::summary`) is the earliest Draft or Approved voucher's amount and payout date. A Draft shows only as this amount. With no open voucher, it is a forecast of the coming cut-off from the entries not yet in a voucher. A negative amount reads "You owe Estatell".
- **Last payout** is the latest Paid voucher, with its paid date and bank reference.
- A row opens the reservation when the user has `reservations.view`.
- **Export CSV:** every filtered row with the page columns, `payouts-{from}-to-{to}.csv`.

## Payouts tab

`/p/{property:code}/finance/payouts/vouchers` (Index), the "previous months' payments".

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Payouts                                                                      │
│ [▤ Bookings] [▧ Payouts]                                                     │
├──────────────────────────────────────────────────────────────────────────────┤
│ ┌────────────┬────────────────────┬────────────┬─────────────┬─────────┬───┐ │
│ │Voucher     │Cycle               │Paid on     │Amount       │Status   │   │ │
│ ├────────────┼────────────────────┼────────────┼─────────────┼─────────┼───┤ │
│ │PV-2026-0011│16 Oct – 15 Nov     │—           │RM 8,420.00  │Approved │PDF│ │
│ │PV-2026-0009│16 Sep – 15 Oct     │1 Nov 2026  │RM 3,010.00  │Paid     │PDF│ │
│ └────────────┴────────────────────┴────────────┴─────────────┴─────────┴───┘ │
└──────────────────────────────────────────────────────────────────────────────┘
```

A row opens `/p/{property:code}/finance/payouts/vouchers/{payoutVoucher}`: the admin voucher page, read-only, with no action buttons except PDF and no approver name. The PDF is at `…/{payoutVoucher}/pdf`.
