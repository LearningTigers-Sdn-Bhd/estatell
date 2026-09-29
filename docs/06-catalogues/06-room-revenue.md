# Room revenue

## Purpose

Choose the taxes on room revenue in one place, and show what a guest pays for a sample room night.

## Room revenue codes

One set of taxes covers all five room revenue codes (`SystemTransactionCode::roomRevenueCases()`):

| Code            | Charge              |
| --------------- | ------------------- |
| ROOM            | Room night          |
| NO_SHOW         | No-show charge      |
| CANCEL          | Cancellation charge |
| LATE_CHECKOUT   | Late checkout       |
| EARLY_DEPARTURE | Early departure     |

`UpdateRoomRevenueTaxes` links the chosen taxes to every one of these codes in the `tax_transaction_code` pivot, so they cannot drift apart. There is no per-code setting.

## Page

`/p/{code}/settings/room-revenue` (`settings.room-revenue.*`, permission `settings.manage`). Settings → Billing setup → Room revenue.

Two columns, no cards. They stack on narrow screens.

- **Left, taxes**: one checkbox card per tax. The title is the tax name. The description states the rule, for example "8.00% of the room price · added to the price · all guests". Taxes that are turned off can be ticked and show an "Off" badge; they are skipped until they are turned on. **Save taxes** saves the selection.
- **Right, posting preview**: a MYR 100.00 room night for a Malaysian guest and a foreign guest, because foreign-only taxes need a nationality. It updates while boxes are ticked: the page posts the unsaved selection to `settings.room-revenue.preview`, and `RoomRevenuePreview` calculates it with `TaxCalculator::calculateWithTaxes()`. Nothing is saved by the preview. Ticked taxes that are off show as "Off". When included taxes exceed the sample price, the preview shows a message instead of amounts.
- The browser never calculates tax; it only shows the server's numbers.

## Deferred

- Reservation policies (cancellation, no-show) belong to reservations and may become a second section here.
- Whether a tax change reaches existing bookings needs the booking tax snapshot. It is decided in reservations.

## Lessons from wastays

wastays had one Room revenue page that edited ROOM only; late checkout, early departure and cancellation inherited ROOM's rules in code, and no-show used the booking snapshot. estatell links all five codes directly, so `TaxCalculator` needs no inheritance rule.
