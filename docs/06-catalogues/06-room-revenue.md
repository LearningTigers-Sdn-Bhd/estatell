# Room revenue

## Purpose

Choose the taxes on room revenue in one place, show what a guest pays for a sample room night, and set the charge and the text for no-show, cancellation, early departure and late check-out.

## Room revenue codes

One set of taxes covers all five room revenue codes (`SystemTransactionCode::roomRevenueCases()`) by default. A policy can have its own taxes instead, see [Own taxes](#own-taxes):

| Code            | Charge              |
| --------------- | ------------------- |
| ROOM            | Room night          |
| NO_SHOW         | No-show charge      |
| CANCEL          | Cancellation charge |
| LATE_CHECKOUT   | Late checkout       |
| EARLY_DEPARTURE | Early departure     |

`UpdateRoomRevenueTaxes` links the chosen taxes to ROOM and to every policy code that follows the Taxes tab, in the `tax_transaction_code` pivot, so they cannot drift apart. A policy code with its own taxes is skipped.

## Page

The page has five tabs, each with its own URL, controller method and page: **Taxes**, **No-show**, **Cancellation**, **Early departure** and **Late check-out**. The Taxes tab is described here. The policy tabs are under [Policies](#policies).

`/p/{code}/settings/room-revenue` (`settings.room-revenue.*`, permission `settings.manage`). Settings → Billing setup → Room revenue.

Two columns, no cards. They stack on narrow screens.

- **Left, taxes**: a multiselect (`MultiCombobox`). Each option has the tax name and a description of the rule, for example "8.00% of the room price · added to the price · all guests". Taxes that are turned off can be chosen and say "Off, not added until it is turned on" in the description; they are skipped until they are turned on. **Save taxes** saves the selection.
- **Right, posting preview**: a MYR 100.00 room night for a Malaysian guest and a foreign guest, because foreign-only taxes need a nationality. It updates while boxes are ticked: the page posts the unsaved selection to `settings.room-revenue.preview`, and `RoomRevenuePreview` calculates it with `TaxCalculator::calculateWithTaxes()`. Nothing is saved by the preview. Ticked taxes that are off show as "Off". When included taxes exceed the sample price, the preview shows a message instead of amounts.
- The browser never calculates tax; it only shows the server's numbers.

## Policies

One row per property and code in `room_revenue_policies`, for `NO_SHOW`, `CANCEL`, `EARLY_DEPARTURE` and `LATE_CHECKOUT`. `SeedPropertyDefaults` creates the four rows through `SeedRoomRevenuePolicies`, as Free, with a short default text in English and Malay, for example "No charge for cancellation." The seeder is idempotent and keeps edited rows. Typed columns only, no jsonb.

```
room_revenue_policies   id, property_id, code (SystemTransactionCode), charge_mode (PolicyChargeMode),
                        amount (nullable), percent (nullable), percent_basis (PolicyRateBasis, nullable),
                        applies_to (PolicyScope), free_until_days (nullable, Cancel only),
                        own_taxes (bool, default false),
                        text_en, text_ms, timestamps
                        unique (property_id, code)
```

| Field             | Values                                                                       |
| ----------------- | ---------------------------------------------------------------------------- |
| `charge_mode`     | Free, FixedAmount, Percentage                                                |
| `percent_basis`   | BookedRate (`unit_nights.amount`), CurrentRate (a fresh quote)               |
| `applies_to`      | FirstNight, WholeStay, UnusedNights, OneCharge                               |
| `free_until_days` | Cancellation only: free when cancelled this many days or more before arrival |

| Policy          | Allowed `applies_to`                     |
| --------------- | ---------------------------------------- |
| No-show         | FirstNight, WholeStay                    |
| Cancellation    | FirstNight, WholeStay                    |
| Early departure | UnusedNights                             |
| Late check-out  | OneCharge (a percentage is of one night) |

- **Booked rate** is the stored nightly amount. **Current rate** is a quote for the same plan, room type and dates. When no current rate exists, the calculator falls back to the booked rate (to verify when built).
- `PolicyChargeCalculator::calculate($policy, $unit, $newDeparture = null)` (`app/Services/Catalogues/`) reads the policy and the unit and returns a `PolicyCharge` (amount before tax, and whether the free window made it free). It does not post. A fixed amount is one charge. A percentage is taken of the booked nights chosen by `applies_to` (first night, all nights, the nights from `$newDeparture`, or the last night), rounded half up to the cent. Unused nights need `$newDeparture`, and the unit still holds its booked nights when it is called. The front desk Actions post it through `PostFolioCharge` with the policy's system code, so the taxes chosen on the Taxes tab apply. Each posting has a unique idempotency key.
- A staff member with `front-desk.waive-fees` can choose **Do not charge** in the form. The reason is required and the choice is logged.
- Free until: a cancellation made inside the window is free. Without it, every cancellation follows the charge settings.
- Mapping from the old rules: no-show None is Free, FirstNight and FullStay are 100% of the booked rate; early departure ChargeUsedNights is Free and ChargeFull is 100% of the unused nights.
- The policy applies at the moment of the action. A booking does not keep a copy of the policy. A snapshot waits for the confirmation document.
- A late check-out is not charged by a catalogue item. The folio rule "charges from catalogue items only" is for manual posting.
- `NO_SHOW` is still only posted when `missed_arrival_policy` marks the unit as a no-show.

### Policy tab

The tabs are at `/p/{code}/settings/room-revenue` (Taxes) and `/no-show`, `/cancellation`, `/early-departure` and `/late-checkout` under it (`settings.room-revenue.policies.show`, saved by `PUT` to the same URL as `.update`, previewed by `POST …/{tab}/preview` as `.preview`). `PolicyTab` maps the URL segment to the system code. A tab with one allowed scope (early departure, late check-out) sets it on the server. The preview prices a sample stay of 3 nights at 100.00 with the taxes of the code. It uses the booked rate, so a Current rate policy shows the same figures. A cancellation with a free window shows one case at the window and one a day later.

A form on the left and a preview on the right, like the Taxes tab. The preview posts the unsaved form to a preview route and shows the server's numbers for a sample room night and stay, with the taxes. Hidden fields: "Free until" outside Cancellation, "Applies to" for Late check-out. The text has an English and a Malay field. Saving needs `settings.manage`.

```
┌ Cancellation ──────────────────────┐ ┌ Preview ───────────────────┐
│ Charge   (•) Free  ( ) Amount      │ │ Room night RM 280.00       │
│          ( ) Percentage            │ │ Cancel 5 days before: Free │
│ Percent  [ 100 ] % of [Booked ⌄]   │ │ Cancel 1 day before:       │
│ Applies  [ First night ⌄ ]         │ │   RM 280.00 + tax          │
│ Free until [ 2 ] days before       │ └────────────────────────────┘
│ Policy text  [English] [Malay]     │
│                        [ Save ]    │
└────────────────────────────────────┘
```

### Own taxes

Both the Taxes tab and the policy tabs pick taxes with the same multiselect. Posting and the preview already read the taxes of the policy's own code, so `TaxCalculator` and `PostFolioCharge` do not change.

- `room_revenue_policies.own_taxes` (boolean, default false). `RoomRevenueTaxIds` reads the taxes of a code: ROOM for the Taxes tab and a policy that follows, the policy's own code otherwise. False means the policy follows the Taxes tab. True means its code keeps its own taxes.
- The Taxes tab save links the chosen taxes to ROOM and to each policy whose `own_taxes` is false. It never touches a policy with its own taxes. A tax added later reaches every policy that still follows.
- A policy tab has a **Taxes** section with a **Follow the Taxes tab** switch. On shows the Taxes tab selection in a disabled multiselect. Off makes the multiselect editable, saved with the policy. An empty choice is allowed, for example an untaxed no-show fee. Turning the switch back on links the code to the Taxes tab selection again. A short note says that a policy with its own taxes does not change when the Taxes tab changes.
- Row 1 of a policy tab has three columns. **How it charges** spans two (the charge cards, then the charge form) and **Taxes** spans one. Row 2 holds **Policy text** and **Preview**. On narrow screens they stack.
- The policy preview posts the unsaved tax choice, like the Taxes tab preview, so staff see the effect before saving.
- Charges already posted keep the taxes they were posted with. The ledger is not changed.
- Saving logs the change, with the tax ids and whether the policy follows.

## Deferred

- A per-rate-plan override of the early departure policy.
- A policy snapshot on the booking, with the confirmation document.
- Whether a tax change reaches existing bookings needs the booking tax snapshot. It is decided in reservations.

## Lessons from wastays

wastays had one Room revenue page that edited ROOM only; late checkout, early departure and cancellation inherited ROOM's rules in code, and no-show used the booking snapshot. estatell links all five codes directly, so `TaxCalculator` needs no inheritance rule, and an own-taxes policy only changes which taxes are linked to its code.
