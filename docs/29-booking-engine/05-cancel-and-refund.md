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

`CancelBookingOnline` runs `CancelUnits`, then the refund. The actor is null for a guest, as in [confirmation](04-payment.md#confirmation). To verify at build: with a null actor, the activity log uses the signed-in user of the current guard, so under `auth:sanctum` the entry may name the `BookingClient`. `RefundPayment` takes a null actor for an automatic refund. When staff cancel a booking engine booking from the PMS, the same refund step runs after the Dialog, and the Dialog shows the refund amount.

## Refund status

`RefundPayment` posts straight away and rows cannot be edited, while a Curlec refund takes time and can fail. So the steps are:

```
Staff or the system asks for a refund
        │
        ▼
estatell saves it as Pending
(nothing on the folio yet)
        │
        ▼
estatell asks Curlec ◄───────────── Curlec is down or its balance is low:
        │                           stays Pending, asks again every 15 minutes
        ▼
Curlec gives its result
        │
        ├─ Success ─► Processed. The refund line posts on the folio.
        │
        └─ Failed ──► Failed. Staff and admins are alerted.
                      The credit stays on the folio.
                      Staff click Retry, and it goes back to Pending.
```

1. `RequestOnlineRefund` writes a `gateway_refunds` row (Pending) and one `gateway_refund_parts` row for each `payments` row it gives back. It locks the Curlec payment and the folios, so two requests cannot exceed a payment.
2. After commit, `SendOnlineRefundJob` runs `SendOnlineRefund`, which calls Curlec. The `receipt` is `refund-{id}-{attempt}`, which Curlec treats as an idempotency key. The notes hold our row's ID, so a webhook that arrives before Curlec's answer still finds its row.
3. On `refund.processed`, `CompleteOnlineRefund` marks the row Processed and calls `RefundPayment` once for each part, in one transaction. A group booking has one Curlec refund and one fee, with one line for each unit. If a line is refused, everything rolls back, the row stays Pending, and the queue tries again.
4. On `refund.failed`, `FailOnlineRefund` marks the row Failed. Staff with `payment.refund` and admins are alerted, and the credit stays on the folio until it is retried.

The line is posted under `requested_by`, the person who asked, so it can close a settled folio. An automatic refund has a null actor, so its folio stays Open for staff to close.

| Refund event                             | Result                                                                                    |
| ---------------------------------------- | ----------------------------------------------------------------------------------------- |
| Processed or failed, with a matching row | As in the steps above                                                                     |
| `captured` is false, no row              | Ignored: Curlec's auto-refund of a payment that was never captured                        |
| On a payment estatell did not take       | Ignored                                                                                   |
| `captured` is true, on our payment       | Admins are alerted, because the refund was made outside estatell. Nothing posts by itself |
| `refund.created`, `refund.speed_changed` | Ignored                                                                                   |

While a refund is Pending, the folio shows it above the rows and it counts against the amount left to refund and the credit. A folio with a Pending refund cannot close (`online_refund_pending`). Curlec's normal refunds take 0–3 days for FPX and e-wallets and 5–10 days for cards, so guest wording says so. The guest's page comes with `let guests cancel online`.

### Check-out

A check-out with Online credit goes ahead. `CheckOutAndRefund` and `CheckOutGroup` ask Curlec for the Online part, and a Pending refund counts as given back in `CheckOutSettlement::settledBalance`. The unit checks out, its folio stays Open, and the webhook posts the refund and closes the folio. If the refund then fails, the credit stays on that Open folio for staff to retry.

## Rules for Online payments

- An Online payment refunds only through Curlec, because estatell holds the money. `RefundPayment` refuses it (`online_refund_only`) unless Curlec has already confirmed the refund. Another method is refused too, even with `payment.refund-other-method`.
- Void does not apply to an Online payment.
- Every staff refund goes through `RefundHeldPayment`, which runs `RequestOnlineRefund` for an Online payment: the folio **Refund** action, **Refund credit**, check-out and group check-out.
- A refund made in the Curlec dashboard is recorded on the [manual folio](../13-folio/08-manual-folio.md), with the Curlec refund ID as its reference. This is the only way to record one by hand.
- A support user's refund follows `allow_support_money_corrections`, like any refund.

## When a refund cannot run

| Problem                   | Result                                                                                                 |
| ------------------------- | ------------------------------------------------------------------------------------------------------ |
| Curlec balance too low    | The booking stays cancelled, the refund stays Pending, and admins are alerted once                     |
| Curlec cannot be reached  | The same. `ResendWaitingOnlineRefunds` sends a Pending refund with no Curlec ID again every 15 minutes |
| Curlec refund fails       | Failed, with an alert. Staff retry from the folio, unless the money went back another way since        |
| Payment over 6 months old | Should not happen with the booking limit. A late one is handled in the dashboard, and recorded by hand |

Curlec's error wording decides between "try again" and "failed". To verify on the demo: the low balance and duplicate receipt wording in a real answer.

## Cost

- Curlec does not return its fee on a refund, and charges RM 0.50 per normal refund.
- **The property pays both**, from its next payout. On a RM 432 card payment, a full refund costs RM 10.87 (RM 10.37 + RM 0.50).
- On a partial refund, the property pays the RM 0.50, and the original fee stays covered by the commission on what it keeps. This is an assumption for Commission fees to confirm.

Sources: [Normal refund fees](https://curlec.com/docs/payments/refunds/normal#refund-fees), [Refunds](https://curlec.com/docs/payments/refunds), [Create a normal refund](https://curlec.com/docs/api/refunds/create-normal), [Refund webhook events](https://curlec.com/docs/webhooks/refunds).
