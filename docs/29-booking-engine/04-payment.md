# Payment

## Purpose

Take the guest's full payment through Razorpay Curlec and turn it into a confirmed booking. estatell never handles card details.

## Flow

```
1. Guest clicks Pay on the microsite or owner's site
        │
        ▼
2. App calls POST /holds/{token}/payment
        │
        ▼
3. estatell creates a Curlec order, using its secret key
   and the amount it calculated itself
        │
        ▼
4. estatell returns key_id and order_id (safe to show)
        │
        ▼
5. Curlec checkout opens; guest pays by FPX, card or e-wallet
        │
        ├──► 6a. Guest returns to estatell's callback address
        │        estatell checks the signature, then sends the
        │        guest to a "confirming your booking" page
        │
        └──► 6b. Curlec sends the order.paid webhook
                 estatell checks the signature, then creates
                 the reservation, folio and payment line
        │
        ▼
7. Page checks GET /holds/{token} until Confirmed
```

Steps 6a and 6b can arrive in either order. **Only the webhook creates a booking**, so a guest who closes the page still gets one, and a changed browser response has no effect.

## Data model

```
gateway_payments   id, property_id, booking_hold_id, gateway_account,
                   order_id (unique), gateway_payment_id (unique, nullable),
                   amount, status (GatewayPaymentStatus), gateway_payload (jsonb, nullable),
                   timestamps
gateway_refunds    id, property_id, gateway_payment_id, gateway_refund_id (unique, nullable),
                   amount, status (GatewayRefundStatus), reason (nullable),
                   failure_reason (nullable), gateway_payload (jsonb, nullable),
                   timestamps
gateway_events     id, gateway_account, event_id (unique), event_type, payload (jsonb),
                   processed_at (nullable), timestamps
```

- The raw payloads use the jsonb allowlist for gateway payloads.
- `gateway_account` records which Curlec account took the payment, so a later account move can still refund old payments.
- **`payments` stays as it is.** A trigger rejects every UPDATE on it, so a row cannot go from Pending to Completed. `gateway_payments` tracks the attempt, and the `payments` row is written once the money is confirmed. `PaymentStatus::Pending`, `Failed` and the `gateway_payload` note in [Payments](../14-payments/01-payments-and-refunds.md) are not needed for this.
- `GatewayPaymentStatus`: Created, Paid, Failed. `GatewayRefundStatus`: Pending, Processed, Failed.

## Gateway code

- One class, `CurlecGateway` (`app/Services/Payments/`), with `createOrder`, `fetchPayment`, `verifySignature`, `verifyWebhook` and `refund`.
- It is built from the wastays `razorpay` adapter's flow, using the Razorpay API that Curlec documents. There is no multi-gateway registry.
- Keys are in config: `CURLEC_KEY_ID`, `CURLEC_KEY_SECRET`, `CURLEC_WEBHOOK_SECRET`.
- Order notes hold `app: estatell` and the hold token only. Guest identity data never goes to the gateway.

## Curlec rules

| Rule from Curlec's docs                                                               | What estatell does                                                                                   |
| ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| FPX requires `callback_url` and `redirect: true`                                      | Redirect mode for every payment method                                                               |
| The callback domain must be allowlisted                                               | Curlec returns guests to estatell, which sends them on to the microsite or an approved owner address |
| Checkout accepts a `timeout`                                                          | The hold's remaining time                                                                            |
| Any non-2xx response is a failure; retried for 24 hours, then the webhook is disabled | Always answer 2xx for a valid signature                                                              |
| Must answer within 5 seconds                                                          | Save the event, then process it in a queue                                                           |
| Events can arrive more than once                                                      | One event row by `event_id`, one booking per order                                                   |
| Up to 30 webhook URLs per account                                                     | estatell has its own webhook                                                                         |

## Webhook

`POST /webhooks/curlec` (`webhooks.curlec`) in `routes/webhooks.php`.

1. Check the signature against the raw body. A bad signature returns 400 and saves nothing.
2. Save the event by `event_id` and answer 200.
3. A queued job processes it. An order estatell did not create is marked processed and ignored.

| Event                               | Does                                                           |
| ----------------------------------- | -------------------------------------------------------------- |
| `order.paid`                        | Marks the hold Paid, then [confirms](#confirmation)            |
| `payment.failed`                    | Records it. The guest can retry until the hold expires         |
| `refund.processed`, `refund.failed` | See [Cancel and refund](05-cancel-and-refund.md#refund-status) |

To verify at build: the event ID header and the exact refund event names.

## Confirmation

`ConfirmBookingHold` runs in one transaction, once per order.

1. Mark the hold Confirmed. This comes first because a Paid hold counts in availability, so `AddUnit` would otherwise refuse the hold's own rooms. Other requests still see it as Paid until the commit, so nobody can take the rooms.
2. Create a new guest from the hold's details. `GuestMatcher` is not used to merge; staff merge later.
3. Create the booking through `CreateBooking`, with the Booking Engine source and each line's plan and price.
4. Save the [cancellation terms](05-cancel-and-refund.md#saved-terms) shown to the guest.
5. Post the payment on the folio.
6. Link the reservation to the hold.
7. After commit: email the guest the confirmation and Manage booking link, and notify staff.

A guest is not a `User`, so the actor is null in `CreateBooking`, `AddUnit`, `CancelUnits` and `RecordPayment`, and in the Actions they call.

- `reservations.created_by`, `payments.received_by` and `folio_lines.user_id` stay empty, and the log entries have no causer. `reservation.created` names the source.
- All rules still apply. A null actor is not `fromChannel`, so availability and restrictions are checked. It cannot override a restriction or use an Old system method.
- The receipt and the Payments report show "Booking Engine" as the staff name.
- `CloseSettledUnitFolio` leaves the folio Open with a null actor, because a closed folio must name who closed it (`folios_closed_check`). Staff close it. This does not affect a booking at confirmation, because its unit has not checked out.

### Paid after the hold expired

A bank can confirm late. When `order.paid` arrives for an Expired hold, the rooms are checked under the room type lock.

| Rooms      | Result                                                                              |
| ---------- | ----------------------------------------------------------------------------------- |
| Still free | Confirm as normal                                                                   |
| Taken      | Refund the whole payment automatically, email the guest, and alert staff and admins |

## On the folio

| Item          | Rule                                                                                                 |
| ------------- | ---------------------------------------------------------------------------------------------------- |
| Method        | A system **Online** method (`PaymentMethodType::Online`), no surcharge, left out of the desk cash-up |
| Purpose       | Advance                                                                                              |
| Reference     | The Curlec payment ID                                                                                |
| Key           | `online:{order}:{unit}`, so a repeat posts nothing                                                   |
| Group booking | One Curlec payment becomes one `payments` row per unit, each for that unit's share                   |
| Receipt       | A receipt number, as for a payment at the desk, because the guest paid for this stay                 |

`RecordPayment` needs a business date. A property in the module is Live, so it has one.

## Failures

| Situation                   | Result                                                                       |
| --------------------------- | ---------------------------------------------------------------------------- |
| Guest cancels or bank fails | No booking. The guest can retry until the hold expires                       |
| Guest closes after paying   | The webhook creates the booking and sends the email                          |
| Webhook delayed             | The page keeps showing "confirming", and can ask Curlec for the order status |
| Confirmation refused        | The event stays unprocessed, the queue retries, and admins are alerted       |

## Environments

| Environment     | Gateway                                   |
| --------------- | ----------------------------------------- |
| Automated tests | A fake gateway class, bound only in tests |
| Local and demo  | Curlec test keys, with its mock bank page |
| Production      | Curlec live keys only                     |

No route confirms a booking without a verified payment.

## Fees

The account reuses the wastays Razorpay Curlec account. On Curlec's published Basic plan, before tax: FPX 1.50% (minimum RM 1), domestic card 2.40%, foreign card 3.30%. Commission and payouts belong to Commission fees, which reads `gateway_payments` and `gateway_refunds`.

## Before going live

- Remove the old wastays webhook address from the Curlec dashboard.
- Create new API keys for estatell, and retire the old ones.
- Add estatell's callback domain to the allowlist.
- Confirm the account uses automatic capture.

Sources: [Integration steps](https://curlec.com/docs/payments/server-integration/nodejs/integration-steps), [FPX flow](https://curlec.com/docs/payments/payment-methods/fpx#payment-flow), [Webhook best practices](https://curlec.com/docs/webhooks/best-practices), [Webhook events](https://curlec.com/docs/webhooks/payments), [Pricing](https://curlec.com/pricing/). Curlec's docs are also a public read-only MCP server at `https://curlec.com/docs/mcp`.
