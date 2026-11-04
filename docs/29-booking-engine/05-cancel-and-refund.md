# Cancel and refund

## Purpose

Let a guest cancel an online booking and get their money back, following the terms they saw when they booked. This is not a separate module.

| Module          | Owns                                                            |
| --------------- | --------------------------------------------------------------- |
| Booking engine  | The guest's cancel page and API, the terms saved at booking     |
| Front desk      | `CancelUnits`, the policy fee on the folio, releasing the room  |
| Payments        | Refunds through Curlec and their status                         |
| Commission fees | Commission on the amount kept, and Curlec's fees off the payout |

## Flow

```
Guest opens the Manage booking link
        │
        ▼
Preview: fee RM 216.00, refund RM 216.00
        │
        ▼
Guest confirms
        │
        ▼
Front desk: CancelUnits posts the fee and frees the room
        │
        ▼
Payments: asks Curlec to refund the balance
        │
        ▼
Curlec's refund webhook ─► refund line posted on the folio
        │
        ▼
Email to guest, staff notified, Commission fees updated
```

**Refund = amount paid − cancellation fee − tax on the fee.**

The refund runs automatically. Staff do not approve it, because the property's policy sets the amount.

## Saved terms

Today the policy applies at the moment of the action, and a booking keeps no copy. An online guest was promised specific terms, so a booking engine booking keeps them.

```
reservation_cancellation_terms
    id, property_id, reservation_id (unique), charge_mode (PolicyChargeMode),
    amount (nullable), percent (nullable), percent_basis (PolicyRateBasis, nullable),
    applies_to (PolicyScope), free_until_days (nullable), own_taxes,
    text_en, text_ms, timestamps
```

- Written at [confirmation](04-payment.md#confirmation), from the `CANCEL` row in `room_revenue_policies`. Real columns, no jsonb.
- `PolicyChargeCalculator` uses these terms when they exist, and the live policy otherwise. Staff and portal bookings do not change.
- A later change to the property's policy does not change these bookings.

## Cases

| Case                               | Who starts it                      | Result                                                                      |
| ---------------------------------- | ---------------------------------- | --------------------------------------------------------------------------- |
| Inside the free window             | Guest or staff                     | Full refund, no commission; the property pays Curlec's fees                 |
| After the free window              | Guest or staff                     | The fee is kept and the rest refunded; commission on the fee                |
| Staff choose Do not charge         | Staff with `front-desk.waive-fees` | Full refund                                                                 |
| On or after the arrival date       | Staff only                         | Online cancel closes, and the guest contacts the property                   |
| No-show                            | Staff or the night audit rule      | The fee is posted, and any leftover stays as credit for staff to refund     |
| Early departure or goodwill refund | Staff, from the folio              | Refund through Curlec; money already paid out comes off the next payout     |
| Reinstating a cancelled unit       | Staff                              | The guest owes the amount again; the old refund is not undone               |
| Group booking                      | Guest                              | Whole booking only, as in the company portal; staff can cancel single rooms |

`CancelBookingOnline` runs `CancelUnits`, then the refund. The actor is null for a guest, as in [confirmation](04-payment.md#confirmation). To verify at build: with a null actor, the activity log uses the signed-in user of the current guard, so under `auth:sanctum` the entry may name the `BookingClient`. `RefundPayment` still needs a `User`; the refund item decides this for an automatic refund. When staff cancel a booking engine booking from the PMS, the same refund step runs after the Dialog, and the Dialog shows the refund amount.

## Refund status

`RefundPayment` posts straight away and rows cannot be edited, while a Curlec refund takes time and can fail. So the steps are:

1. `RequestOnlineRefund` writes a `gateway_refunds` row (Pending) and calls Curlec with an idempotency key.
2. On `refund.processed`, the job calls `RefundPayment`, which posts the refund line. For a group booking, one call for each unit's `payments` row.
3. On `refund.failed`, the row becomes Failed. Staff and admins are alerted, and the credit stays on the folio until it is retried.

While a refund is Pending, the folio and the guest's page show "Refund pending RM 216.00", and it counts against the amount left to refund. Curlec's normal refunds take 5–7 working days to reach the guest, so the cancel page and email say so.

## Rules for Online payments

- An Online payment refunds only through Curlec, because estatell holds the money. `RefundPayment` refuses another method for it (`online_refund_only`), even with `payment.refund-other-method`.
- Void does not apply to an Online payment.
- Folio **Refund credit** for an Online payment runs `RequestOnlineRefund`.
- A support user's refund follows `allow_support_money_corrections`, like any refund.

## When a refund cannot run

| Problem                   | Result                                                                                                 |
| ------------------------- | ------------------------------------------------------------------------------------------------------ |
| Curlec balance too low    | The booking stays cancelled, the refund waits and retries, and admins are alerted                      |
| Curlec refund fails       | Failed, with an alert. Staff or admins retry from the folio                                            |
| Payment over 6 months old | Should not happen with the booking limit. A late one is handled in the dashboard, and recorded by hand |

## Cost

- Curlec does not return its fee on a refund, and charges RM 0.50 per normal refund.
- **The property pays both**, from its next payout. On a RM 432 card payment, a full refund costs RM 10.87 (RM 10.37 + RM 0.50).
- On a partial refund, the property pays the RM 0.50, and the original fee stays covered by the commission on what it keeps. This is an assumption for Commission fees to confirm.

Sources: [Normal refund fees](https://curlec.com/docs/payments/refunds/normal#refund-fees), [Refunds](https://curlec.com/docs/payments/refunds).
