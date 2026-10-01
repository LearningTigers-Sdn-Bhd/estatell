# Booking form

## Purpose

Create a booking in as few fields as possible, with the same flow front desk staff know from wastays.

## Container and layout

A full page (Form archetype): it has repeating lines and many fields. Two columns from `xl`: the form on the left, a sticky summary rail on the right. Below `xl` the rail moves under the form. `FormActions` stays at the bottom.

```
┌────────────────────────────────────────────────────────────┬─────────────────────────┐
│ STAY                                                       │ SUMMARY        (sticky) │
│ Arrival [12 Oct 2026] Departure [15 Oct 2026] · 3 nights   │ Units       5           │
│ Source [Phone ▾]  Ext. ref [________]                      │ Rooms     RM 2,310.00   │
│                                                            │ Add-ons   RM    80.00   │
│ ROOMS AND BEDS                                             │ Tax       from folio    │
│ Room type │ Rate plan │ Room / Bed │ Ad │ Ch │ Rate     │   │ Total     RM 2,390.00   │
│ Deluxe ▾  │ BAR RO ▾  │ Select later ▾│ 2 │ 2 │ 840.00 ⓘ ✎ 🗑│ │                         │
│   Children's ages: [4] [9]                                 │ Billing (2+ units)      │
│ Dorm ▾    │ Backpacker▾│ Beds [3]            │ 270.00 ⓘ ✎ 🗑│ │ (•) Master pays rooms   │
│   Bed 1 [D1-2 ▾]  Bed 2 [Select later ▾]  Bed 3 [ … ▾]     │ ( ) Each pays           │
│ [+ Add line]                                               │                         │
│                                                            │ Guests                  │
│ GROUP (2+ units)  Group name [Tan family trip]             │  Tan Wei Ming  booker   │
│                                                            │  Ahmad Faiz    Bed 1    │
│ GUESTS                                                     │  2 units without guest  │
│ [Booker] [Ahmad Faiz ✕] [Guest 3 ✕]        [+ Add guest]   │                         │
│ (fields of the open tab)                                   │                         │
│                                                            │                         │
│ EXTRAS (optional)  Add-ons · Discount                      │                         │
│ NOTES                                                      │                         │
├────────────────────────────────────────────────────────────┴─────────────────────────┤
│                                                          [Cancel]  [Create booking]  │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

## Entry points

The Reservations page has `New ▾` with **New booking** and **Walk-in**. Walk-in opens the same page with `source = WalkIn` and today's business date as arrival. The walk-in check-in itself comes with [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md).

## Sections

1. **Stay**: arrival, departure, nights (shown), source, external reference.
2. **Rooms and beds**: one line per room type + rate plan.
    - Room lines: adults and children. When children > 0, one required age field per child, under the line.
    - Bed lines: "Beds: N", expanded into N units, with one bed picker per unit.
    - Package plans: departure is locked, the stay total is shown.
    - Rate cell: the server quote from `RateQuoteService`. ⓘ opens the night breakdown. ✎ sets an override (logged, needs a reason).
    - A restriction that fails (stop-sell, closed to arrival or departure, min or max stay) shows a warning under the lines. Staff with `rates.override-restrictions` can continue after entering a reason (see [Daily rates and restrictions](../08-rates/02-daily-rates-and-restrictions.md)).
3. **Group**: shown when the form has 2 or more units. Group name, and `billing_mode` in the rail.
4. **Guests**: see [Guests section](#guests-section).
5. **Extras** (optional): add-ons (see [Add-ons](04-add-ons.md)) and a RoomOnly discount from the [catalogue](../06-catalogues/05-discounts.md).
6. **Notes**: special requests or internal notes.

The rail shows units, room and add-on totals, the billing mode and the guest list. All amounts come from the server. Tax shows "from folio" until [Folios and lines](../13-folio/01-folios-and-lines.md) exists.

## Room / Bed column

Optional. A picked room or bed fills `room_id` or `bed_id` on every night of the unit.

| Case                             | Empty option says | Must pick? |
| -------------------------------- | ----------------- | ---------- |
| Booking, `auto_assign_rooms` off | Select later      | No         |
| Booking, `auto_assign_rooms` on  | Auto-assign       | No         |
| Walk-in                          | Select room       | Yes        |

- The list shows only rooms or beds free on every night of the unit, from `AvailabilityService`. A room picked on another line is disabled.
- Changing the room type or dates reloads the list. A picked room that is no longer free is cleared.
- The database constraint on room or bed and night prevents double booking.
- Mid-stay moves, `AutoAssignRooms` and later changes are in [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md).

## Guests section

Three rows: the section title; the tabs and **+ Add guest** (space between); the fields of the open tab.

```
 GUESTS
 [Booker] [Ahmad Faiz ✕] [Guest 3 ✕]                     [+ Add guest]
 ─────────
 Name [ahmad▌                ]  Phone [+60 __________]  Email [__________]
 ┌ Existing guests ─────────────────────────────────────┐
 │ ▸ Ahmad Faiz      +60 12-345 6789  ahmad@x.com  VIP  │
 │   Ahmad Zulkifli  +60 19-222 1010                    │
 └──────────────────────────────────────────────────────┘
 Stays in [Dorm 6-Bed · Bed 1 ▾]
 ▸ More guest details
```

With an existing guest selected:

```
 ┌ 👤 Ahmad Faiz · +60 12-345 6789 · ahmad@x.com  VIP    [Change guest] ┐
 │ ⚠ This guest is blacklisted: "Damaged room, 2025".                   │
 └──────────────────────────────────────────────────────────────────────┘
 Name [Ahmad Faiz]  Phone [+60 12-345 6789]  Email [ahmad@x.com]
 Stays in [Dorm 6-Bed · Bed 1 ▾]
 [ ] Update guest profile with my changes
 ▸ More guest details
```

### Tabs

| Tab            | Rules                                                                                                                                       |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Booker (first) | Always present, cannot be removed. Becomes `booker_guest_id`. "Stays in" also offers **Not staying**.                                       |
| Other tabs     | One guest staying in a unit. The label is the guest's name, or "Guest N" while empty. ✕ removes the tab.                                    |
| Stays in       | Lists every unit on the form (Deluxe King #1, Dorm Bed 1). The first guest in a unit becomes `is_primary`. A bed unit takes one guest only. |

Units without a guest are allowed. They show "Attach guest" on the reservation page.

### Autocomplete

- Name, Phone and Email are autocompletes. Search starts after 2 characters, through `useHttp` to `guests.search`, debounced and cached per query. It returns up to 8 guests of the property. Merged and anonymized guests never show.
- Selecting a guest links the tab to that `guest_id` and fills the fields, including More guest details. The details section opens when the guest has any of them.
- Typed changes to a linked guest update the profile only when **Update guest profile** is on (off by default).
- **Change guest** unlinks the guest, keeps the typed values and focuses Name.
- A blacklisted guest shows the reason as a warning. The booking is still allowed.
- With no guest picked, a new guest is created on save. The `DuplicateWarning` (`guests.matches`, `GuestMatcher`) shows under the fields with "Use existing" and "Create new anyway".

### More guest details

Collapsed by default: date of birth, gender, nationality, address line 1 and 2, city, state, postcode, country, preferred language. No ID document: ID is collected on the [registration card](../16-registration/04-signing-flow.md) at check-in and kept on the guest profile.

### Permissions

This item adds `reservations.view` and `reservations.manage` (see [Reservation page](06-reservation-page.md#permissions)). The booking form needs `reservations.manage`.

| Permission      | Allows                                     |
| --------------- | ------------------------------------------ |
| `guests.view`   | Search and select existing guests          |
| `guests.manage` | Create a guest or update a guest's profile |

Without `guests.manage`, a tab accepts only an existing guest.

## Comes later

| Feature                                  | Module                                                                  |
| ---------------------------------------- | ----------------------------------------------------------------------- |
| Company field                            | [Companies](../15-city-ledger/01-companies.md)                          |
| Tax in the summary, MasterPays routing   | [Folio](../13-folio/01-folios-and-lines.md)                             |
| Collect payment, deposit, payment method | [Payments and refunds](../14-payments/01-payments-and-refunds.md)       |
| Walk-in check-in                         | [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md) |
| Quick booking, backdated check-in        | Not planned yet                                                         |

## Implementation

- Inertia `<Form>` or `useForm` with a Form Request. No client-side validation library.
- The form request maps onto `NewReservationData` and calls `CreateReservation` and `AddUnit` from the [group bookings](02-group-bookings.md#creating-reservations-and-units) item. Before that, the guest tabs create or update guests through the guest Actions.
- This item adds `AttachGuest` and `AssignRoom` / `AssignBed` (whole stay). One Action per concern, no god service.
- `guests.search` is a JSON route that reuses the `GuestDirectory` search. It needs `guests.view`.
- Components: Base UI `Combobox`, `Tabs` and `Collapsible`. The autocomplete field is `components/pms/guests/guest-autocomplete-field.tsx`. More guest details reuse the fields of `guest-form.tsx`.

## Lessons from wastays

- wastays only asked for child ages at per-guest hotels and fell back to a flat child price when they were missing, which overcharged infants and undercharged older children.
- The booking creation form was 335 lines of ERB plus a 761-line `booking_room_rows_controller.js`. `CreateManualBooking` (439 lines) mixed guest matching, rate override, prepayment and audit.
- wastays stored one `room_number` per booking. estatell stores the room per night, so a picked room fills every night.
- wastays asked for ID type and number at booking. estatell leaves ID to the registration card, so it does not store ID for guests who never arrive.
