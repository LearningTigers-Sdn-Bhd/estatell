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

The checkout is Curlec's form, opened by Curlec's script on the microsite or the owner's site. estatell has no payment page of its own. The page's security rules are in [Microsite](08-microsite.md#page-security) and [API only mode](09-api-only-mode.md#page-security).

## Build order

The payment item covers payment and confirmation only. One step in this doc is added by a later item, which must ship before real money is collected, along with Commission fees.

| Step                     | Added by                   | Until then                                |
| ------------------------ | -------------------------- | ----------------------------------------- |
| Saved cancellation terms | `let guests cancel online` | Nothing saved, so the live policy applies |

## Data model

```
gateway_payments   id, property_id, booking_hold_id, gateway_account,
                   order_id (unique), gateway_payment_id (unique, nullable),
                   amount, status (GatewayPaymentStatus), gateway_payload (jsonb, nullable),
                   timestamps
gateway_refunds    id, property_id, gateway_payment_id, gateway_refund_id (unique, nullable),
                   idempotency_key (unique per property), amount, status (GatewayRefundStatus),
                   attempts, reason (nullable), failure_reason (nullable),
                   requested_by (nullable), gateway_payload (jsonb, nullable), timestamps
gateway_refund_parts
                   id, gateway_refund_id, payment_id, amount, timestamps
gateway_events     id, gateway_account, event_id (unique), event_type, payload (jsonb),
                   processed_at (nullable), timestamps
```

- `gateway_refund_parts` names the `payments` rows a refund gives back, so one Curlec refund for a group costs one fee. A late payment whose rooms are taken has no parts, because it has no `payments` row.
- `booking_hold_id` is unique on `gateway_payments`. A hold has one order, and a guest who retries gets the same one, so they cannot pay two orders.
- The raw payloads use the jsonb allowlist for gateway payloads.
- `gateway_account` records which Curlec account took the payment, so a later account move can still refund old payments.
- **`payments` stays as it is.** A trigger rejects every UPDATE on it, so a row cannot go from Pending to Completed. `gateway_payments` tracks the attempt, and the `payments` row is written once the money is confirmed. `PaymentStatus::Pending`, `Failed` and the `gateway_payload` note in [Payments](../14-payments/01-payments-and-refunds.md) are not needed for this.
- `GatewayPaymentStatus`: Created, Paid, Failed. `GatewayRefundStatus`: Pending, Processed, Failed. A Failed refund goes back to Pending on a retry, with a new attempt.

## Gateway code

- One class, `CurlecGateway` (`app/Services/Payments/`), with `createOrder`, `refund`, `verifySignature` and `verifyWebhook`.
- It is built from the wastays `razorpay` adapter's flow, using the Razorpay API that Curlec documents. There is no multi-gateway registry.
- Keys are in `config/services.php`: `CURLEC_KEY_ID`, `CURLEC_KEY_SECRET`, `CURLEC_WEBHOOK_SECRET`. `CURLEC_ACCOUNT` names the account on each row, and defaults to `primary`.
- Order notes hold `app: estatell` and the hold token only. The microsite prefills Curlec's checkout form with the guest's email and phone, so the guest does not type them twice.

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

## Guest return

`GET` or `POST /webhooks/curlec/return/{token}` (`webhooks.curlec.return`) is the callback address. It checks the signature and writes nothing, then sends the guest to the microsite with a 303:

```
{BOOKING_SITE_URL}/{slug}/book/{token}?payment=confirming
{BOOKING_SITE_URL}/{slug}/book/{token}?payment=failed
```

A failed payment or a bad signature returns as `failed`. The page reads the real status from `GET /holds/{token}`.

For an API only property, the guest goes to the return address the payment named (`gateway_payments.return_url`), with the hold token and status added to its query:

```
https://www.sandbayresort.com/booking/done?hold={token}&payment=confirming
```

## Webhook

`POST /webhooks/curlec` (`webhooks.curlec`) in `routes/webhooks.php`.

1. Check the signature against the raw body. A bad signature returns 400 and saves nothing.
2. Save the event by `event_id`, from the `x-razorpay-event-id` header, and answer 200.
3. `ProcessCurlecEventJob` processes it. An order estatell did not create is marked processed and ignored. A repeat of an unprocessed event queues it again, which is safe because one order makes one booking.

If the queue itself is down, the webhook answers 500 so Curlec delivers the event again.

| Event                               | Does                                                           |
| ----------------------------------- | -------------------------------------------------------------- |
| `order.paid`                        | Marks the hold Paid, then [confirms](#confirmation)            |
| `payment.failed`                    | Records it. The guest can retry until the hold expires         |
| `refund.processed`, `refund.failed` | See [Cancel and refund](05-cancel-and-refund.md#refund-status) |

## Confirmation

`ConfirmBookingHold` runs in one transaction, once per order.

1. Mark the hold Confirmed. This comes first because a Paid hold counts in availability, so `AddUnit` would otherwise refuse the hold's own rooms. Other requests still see it as Paid until the commit, so nobody can take the rooms.
2. Create a new guest from the hold's details. `GuestMatcher` is not used to merge; staff merge later.
3. Create the booking through `CreateBooking`, with the Booking Engine source and each line's plan and [paid price](#price).
4. Save the [cancellation terms](05-cancel-and-refund.md#saved-terms) shown to the guest. Added by `let guests cancel online`.
5. Post the payment on the folio.
6. Link the reservation to the hold.
7. After commit: `NotifyOnlineBooking` emails the guest the confirmation, the booking code and the Manage booking link (`OnlineBookingConfirmed`), and notifies reservation staff (`OnlineBookingReceived`). A repeat event for a confirmed hold sends nothing again. The link is `{BOOKING_SITE_URL}/{slug}/manage/{hold token}`: the hold's long random token is the booking token, so there is no second token. If the hold has no email, only staff are told.

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

In the Taken case, the `gateway_payments` row becomes Paid and `RequestOnlineRefund` runs with no actor and no parts. A repeat of the event refunds and tells nobody again. The guest gets `LateOnlinePaymentRefunded`, staff with `payment.refund` get `OnlineRefundNeedsAttention`, and admins get `OnlinePaymentNeedsAttention`. The hold stays Expired, because a Paid hold counts in availability. If this refund fails, admins handle it in the Curlec dashboard, because there is no folio to retry from.

## On the folio

| Item          | Rule                                                                                                                                              |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Method        | A system **Online** method (`PaymentMethodType::Online`), no surcharge, left out of the desk cash-up. Staff never see it in a form or in Settings |
| Purpose       | Advance                                                                                                                                           |
| Reference     | The Curlec payment ID                                                                                                                             |
| Key           | `online:{order}:{unit}`, so a repeat posts nothing                                                                                                |
| Group booking | One Curlec payment becomes one `payments` row per unit, each for that unit's share                                                                |
| Receipt       | A receipt number, as for a payment at the desk, because the guest paid for this stay                                                              |

`RecordPayment` needs a business date. A property in the module is Live, so it has one.

## Failures

| Situation                   | Result                                                                                                                   |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Guest cancels or bank fails | No booking. The guest can retry until the hold expires. The callback handles a failed return as well as a successful one |
| Guest closes after paying   | The webhook creates the booking and sends the email                                                                      |
| Webhook delayed             | The page keeps showing "confirming", and can ask Curlec for the order status                                             |
| Confirmation refused        | The event stays unprocessed, the queue retries, and admins are alerted                                                   |

## Environments

| Environment     | Gateway                                   |
| --------------- | ----------------------------------------- |
| Automated tests | `FakeCurlecGateway`, bound only in tests  |
| Local and demo  | Curlec test keys, with its mock bank page |
| Production      | Curlec live keys only                     |

No route confirms a booking without a verified payment.

Curlec delivers webhooks only to public addresses and rejects localhost. Local checks therefore stop before the booking is created, so test the full flow on the demo.

The test and live modes have separate keys and data. The dashboard's Test Mode switch only changes what the dashboard shows, so it never affects live payments. Each server uses its own keys.

## Price

A booking keeps the room price the guest paid, as an OTA booking keeps the OTA's price.

| At confirmation           | Result                                                                                              |
| ------------------------- | --------------------------------------------------------------------------------------------------- |
| Rates give the same total | No override, and each night keeps its own price                                                     |
| Rates give another total  | `overrideTotal` is the hold's `room_total`, spread over the nights, with the reason "Booked online" |
| The stay cannot be priced | `AddUnit` refuses, and [confirmation is refused](#failures)                                         |

The booking does not use `fromChannel`, so availability and restrictions are still checked. The price of an auto-added item and tax rates are not kept: a change to either during the hold shows as a balance or credit on the folio for staff to settle.

## To verify

| When             | What                                                                       |
| ---------------- | -------------------------------------------------------------------------- |
| Before the build | The account uses automatic capture, and whether `order.paid` depends on it |
| During the build | What `redirect: true` does on a failed payment. Curlec's docs disagree     |
| During the build | How Curlec's form is isolated from the page. Its docs do not say           |
| During the build | Whether FPX has its own test flow                                          |
| During the build | Whether test and live webhooks are set up separately                       |

## Fees

The account reuses the wastays Razorpay Curlec account. On Curlec's published Basic plan, before tax: FPX 1.50% (minimum RM 1), domestic card 2.40%, foreign card 3.30%. Commission and payouts belong to Commission fees, which reads `gateway_payments` and `gateway_refunds`.

## Before going live

- A few weeks before launch, add estatell's domain to the account's business website details and the callback allowlist. Curlec may need to review it.
- At launch, remove the old wastays webhook address from the Curlec dashboard.
- At launch, create new live API keys for estatell, and retire the old ones.

Sources: [Integration steps](https://curlec.com/docs/payments/server-integration/nodejs/integration-steps), [FPX flow](https://curlec.com/docs/payments/payment-methods/fpx#payment-flow), [Webhook best practices](https://curlec.com/docs/webhooks/best-practices), [Webhook events](https://curlec.com/docs/webhooks/payments), [Webhook setup](https://curlec.com/docs/webhooks/setup-edit-payments), [Test and live modes](https://curlec.com/docs/payments/dashboard/test-live-modes), [Pricing](https://curlec.com/pricing/). Curlec's docs are also a public read-only MCP server at `https://curlec.com/docs/mcp`.
