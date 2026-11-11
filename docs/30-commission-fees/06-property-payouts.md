# Property payouts

## Purpose

Let a property track the money Estatell collects for it, the commission per service, and its payouts. Sidebar: **Payouts** in the Finance area.

## Permission

- New permission `payouts.view` (`PermissionEnum::PayoutsView`).
- Given to the Owner role by default. The owner can give it to other roles.
- The page is shown when the property has at least one commission service on.
- Draft vouchers are hidden. The property sees Approved, Paid and Carried forward vouchers only.

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

- The same columns as the admin Bookings tab, without Property. "Out" is Outstanding to hotel.
- **Next payout** is the property's share of the next voucher run, or its Approved voucher. A negative amount reads "You owe Estatell".
- A row opens the reservation.

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

A row opens `/p/{property:code}/finance/payouts/vouchers/{voucher}`: the admin voucher page, read-only, with no action buttons except PDF.
