# Room revenue

## Purpose

Choose the taxes on room revenue in one place, show what a guest pays for a sample room night, and set the charge and the text for no-show, cancellation, early departure and late check-out.

Onboarding sets the room revenue taxes through the **Applies to rooms** column of the Taxes section ([Taxes table](../17-onboarding/02-wizard-steps.md#taxes-table)), with the same `UpdateRoomRevenueTaxes`. The policies stay Free until the owner sets them after go-live.

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

DAY_USE ([Day use](../12-reservations/10-day-use.md)) is a Room category code but not one of these five: it always keeps its own taxes, on the [Day use tab](#day-use-tab).

## Page

The page has five tabs, each with its own URL, controller method and page: **Taxes**, **No-show**, **Cancellation**, **Early departure** and **Late check-out**. The Taxes tab is described here. The policy tabs are under [Policies](#policies).

`/p/{code}/settings/room-revenue` (`settings.room-revenue.*`, permission `settings.manage`). Settings → Billing setup → Room revenue.

Two columns, no cards. They stack on narrow screens.

- **Left, taxes**: a multiselect (`MultiCombobox`). Each option has the tax name and a description of the rule, for example "8.00% of the room price · added to the price · all guests". Taxes that are turned off can be chosen and say "Off, not added until it is turned on" in the description; they are skipped until they are turned on. **Save taxes** saves the selection.
- **Right, posting preview**: a MYR 100.00 room night for a Malaysian guest and a foreign guest, because foreign-only taxes need a nationality. It updates while boxes are ticked: the page posts the unsaved selection to `settings.room-revenue.preview`, and `RoomRevenuePreview` calculates it with `TaxCalculator::calculateWithTaxes()`. Nothing is saved by the preview. Ticked taxes that are off show as "Off". When included taxes exceed the sample price, the preview shows a message instead of amounts.
- The preview lists taxes in calculation order. A percentage tax shows its base under its name ("on 113.60"), see [Tax base](03-taxes.md#tax-base). When a base tax is not ticked here, a note says it is left out, for example "City fee is in its base but not applied here".
- The browser never calculates tax; it only shows the server's numbers.

```
Taxes on room revenue                     │ What a guest pays for 1 night
┌───────────────────────────────────────┐ │ ┌──────────────────────┬─────────┬─────────┐
│ [Service charge ✕][City fee ✕]        │ │ │                      │ Local   │ Foreign │
│ [Sales tax ✕][Levy ✕]             ⌄   │ │ ├──────────────────────┼─────────┼─────────┤
└───────────────────────────────────────┘ │ │ Room                 │  100.00 │  100.00 │
  Sales tax                               │ │ Service charge 10%   │   10.00 │   10.00 │
  8.00% of price + Service charge +       │ │   on 100.00          │         │         │
  City fee · added · all guests           │ │ City fee             │    3.60 │    3.60 │
                                          │ │ Sales tax 8%         │    9.09 │    9.09 │
                                          │ │   on 113.60          │         │         │
                                          │ │ Levy 1%              │    1.09 │    1.09 │
                                          │ │   on 109.09          │         │         │
                                          │ │ Total                │  123.78 │  123.78 │
                          [ Save taxes ]  │ └──────────────────────┴─────────┴─────────┘
```

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

A wider editor sits on the left, with a compact **Example** on the right. The editor holds the charge, taxes and guest-facing policy text. The preview posts the unsaved form to a preview route and shows the server's numbers, with taxes. Saving needs `settings.manage`.

- Charge choices sit in one row on larger screens and stack on mobile. Free hides the charge details. Fixed amount shows one amount charged once, without a scope selector. Percentage shows the rate basis, with a scope selector only when there is a choice. Early departure explains that it covers unused nights; late check-out explains that it covers one night.
- Cancellation shows the **Free cancellation window** for a paid policy. Other policies hide it.
- Charge details use three columns on wide screens: Percent, Rate basis and Applies to. Free cancellation window follows when present. They use two columns at intermediate widths and stack on mobile. A single allowed scope appears as help under Percent. The current-rate explanation appears only when Current rate is selected.
- The guest-facing policy keeps the language dropdown for English and Malay. The editor explains that changing the charge does not rewrite this text.
- The **Example** title and sample-stay description sit inside one card. Cases have no nested cards or dividers. A percentage tax in a case shows its base ("on 55.00"), as on the Taxes tab. Each case explains the charge with server-provided arithmetic: unused nights for early departure, the percentage basis and multiplication, a fixed fee charged once, or why the charge is free. Paid cases show separate local and foreign breakdowns: charge excluding tax, each included or added tax, and the guest total. Included taxes are extracted before the breakdown, not added twice. A tax that does not apply says so. The browser formats the amounts but does not calculate them.
- Pending edits show **Updating…** instead of old amounts. A failed preview shows a message instead of old amounts. Older responses cannot replace a newer example.
- Mobile order: charge, taxes, example, guest-facing policy, save. The tab strip scrolls horizontally. The page uses the New booking fixed-footer pattern: the editor scrolls above `FormActions`, with the save status on the left and **Save policy** on the right. The action stays visible on desktop and mobile.
- The editor resets when the property or policy URL changes. Preserved tab navigation must not carry another policy's form or example into the new tab. The existing unsaved-changes guard stays.

```
Cancellation                              Example
Charge: Free / Fixed amount / Percentage  3 nights at MYR 100.00
Percent / rate basis / scope              One case per cutoff
Free cancellation window                  Charge before tax
Taxes                                     Local / foreign totals
Guest-facing policy [Language ⌄]
Unsaved changes                           [Save policy]
```

### Day use tab

The tab is always shown, also without a Day use plan, because [Check out as day use](../18-front-desk/01-check-in-and-check-out.md#check-out-as-day-use) posts to DAY_USE without one.

At `/p/{code}/settings/room-revenue/day-use`, it has one **Taxes** multiselect for DAY_USE, the same component as the policy tabs but with no Follow switch, and the posting preview for a sample MYR 100.00 day use. `UpdateDayUseTaxes` saves it. Routes: `settings.room-revenue.day-use.show`, `.update` and `.preview` (declared before `{tab}`). All three work without a Day use plan. `SeedDayUseTaxes` runs from `CreateRatePlan` for the property's first Day use plan only when DAY_USE has no taxes linked yet, so taxes the property already set are kept. Empty DAY_USE taxes are linked to the Taxes tab's taxes except flat taxes (a fixed amount per room-night, such as Tourism tax), because a per-night tax does not fit an hourly stay. A later Taxes tab save does not change DAY_USE.

### Own taxes

Both the Taxes tab and the policy tabs pick taxes with the same multiselect. Posting and the preview already read the taxes of the policy's own code, so `TaxCalculator` and `PostFolioCharge` do not change.

- `room_revenue_policies.own_taxes` (boolean, default false). `RoomRevenueTaxIds` reads the taxes of a code: ROOM for the Taxes tab and a policy that follows, the policy's own code otherwise. False means the policy follows the Taxes tab. True means its code keeps its own taxes.
- The Taxes tab save links the chosen taxes to ROOM and to each policy whose `own_taxes` is false. It never touches a policy with its own taxes. A tax added later reaches every policy that still follows.
- A policy tab has a **Taxes** section with a **Use room revenue taxes** switch. On shows the inherited tax names as read-only text, including which taxes are off. Off reveals the editable multiselect, saved with the policy. An empty choice is allowed, for example an untaxed no-show fee. Turning the switch back on links the code to the Taxes tab selection again. A short note says that a policy with its own taxes does not change when the Taxes tab changes.
- Taxes sit below the charge settings in the editor. The example sits beside the editor on larger screens. On narrow screens they stack.
- The policy preview posts the unsaved tax choice, like the Taxes tab preview, so staff see the effect before saving.
- Charges already posted keep the taxes they were posted with. The ledger is not changed.
- Saving logs the change, with the tax ids and whether the policy follows.

## Deferred

- A per-rate-plan override of the early departure policy.
- A policy snapshot on the booking, with the confirmation document.
- Whether a tax change reaches existing bookings needs the booking tax snapshot. It is decided in reservations.

## Lessons from wastays

wastays had one Room revenue page that edited ROOM only; late checkout, early departure and cancellation inherited ROOM's rules in code, and no-show used the booking snapshot. estatell links all five codes directly, so `TaxCalculator` needs no inheritance rule, and an own-taxes policy only changes which taxes are linked to its code.
