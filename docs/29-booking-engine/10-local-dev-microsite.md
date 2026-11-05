# Local development with the microsite

## Purpose

Run estatell and the `estatell-booking-microsite` repo on one machine, so the microsite can be built against a real API. For the microsite itself, see [Microsite](08-microsite.md). For the endpoints, see [API](02-api.md).

```
Browser ──▶ microsite  http://localhost:4321
                │  server routes add the token and X-Guest-IP
                ▼
            estatell   http://localhost:8000/api/booking/v1
                │
                ▼
            Curlec test mode (checkout, mock bank page)
```

The browser never calls estatell's API. Only the microsite's server routes do.

## estatell setup

1. Set these in estatell's `.env`, then run `composer run dev`. It also starts the queue listener, which processes Curlec events.

    | Setting                 | Local value                             | Why                                                   |
    | ----------------------- | --------------------------------------- | ----------------------------------------------------- |
    | `APP_URL`               | `http://localhost:8000`                 | The API address and Curlec's return callback          |
    | `BOOKING_SITE_URL`      | `http://localhost:4321`                 | Where estatell sends the guest back after payment     |
    | `CURLEC_KEY_ID`         | The test key ID, `rzp_test_...`         | Test payments only. Never use live keys locally       |
    | `CURLEC_KEY_SECRET`     | The test key secret                     | Creates orders                                        |
    | `CURLEC_WEBHOOK_SECRET` | Any value, if you sign webhooks by hand | Checks the webhook signature. See [Payment](#payment) |

2. Create the microsite client and copy the token. It is shown once.

    ```bash
    php artisan booking-engine:create-microsite-client
    ```

    Only one active microsite client may exist. To get a new token after a fresh database, run it again.

3. Prepare one property. The microsite client reaches only a published site in Microsite mode, with the flag on.

    | Step                                                                         | Where                                                    |
    | ---------------------------------------------------------------------------- | -------------------------------------------------------- |
    | Turn on **Booking engine**, mode **Microsite**                               | Admin › Properties › the property › Features             |
    | Mark the template **Available**                                              | Admin › Website templates                                |
    | Pick the template, add the required images                                   | Settings › Booking website › Design                      |
    | Add property photos, photos on every room type, and the Google Maps location | Property details › Photos, each room type, Nearby places |
    | Turn on at least one rate plan                                               | Settings › Booking website › Rates                       |
    | **Publish**                                                                  | Settings › Booking website › General                     |

## Microsite setup

The microsite repo owns its own settings. These names are a proposal until that repo is created.

| Setting              | Local value                            |
| -------------------- | -------------------------------------- |
| `ESTATELL_API_URL`   | `http://localhost:8000/api/booking/v1` |
| `ESTATELL_API_TOKEN` | The token from step 2                  |

Every server call sends `Authorization: Bearer {token}`. Creating a hold also needs `X-Guest-IP`, because the microsite client must send it. Locally, send `127.0.0.1`. Writes take an `Idempotency-Key`: a new key for each new attempt, the same key for a retry.

Check the token works. Use the slug of the property you published:

```bash
curl -H "Authorization: Bearer $ESTATELL_API_TOKEN" http://localhost:8000/api/booking/v1/sites/{slug}
```

A 404 means the site is not published, not in Microsite mode, or the flag is off.

## Payment

Locally, everything up to Curlec's checkout works. Curlec's return callback is a browser redirect, so it also works: the guest lands on `http://localhost:4321/{slug}/book/{token}?payment=confirming`.

Curlec cannot send webhooks to localhost, so without help the hold stays **paying** and no booking is made. Pick one:

| Option                   | Use when                                                                                                          |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| Sign the webhook by hand | Most work. Fast, and needs no extra tools                                                                         |
| Test on the demo         | Checking the real Curlec webhook. See [Test mode](09-api-only-mode.md#test-mode). Its Coolify setup is still open |

To sign the webhook by hand, pay on Curlec's test checkout, then copy the order ID (`order_...`) and payment ID (`pay_...`) from the payment response or the Curlec test dashboard. Send `order.paid` with the same webhook secret as estatell's `.env`:

```bash
ORDER=order_xxx PAYMENT=pay_xxx
BODY="{\"event\":\"order.paid\",\"payload\":{\"payment\":{\"entity\":{\"id\":\"$PAYMENT\",\"order_id\":\"$ORDER\",\"status\":\"captured\"}},\"order\":{\"entity\":{\"id\":\"$ORDER\",\"status\":\"paid\"}}}}"
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$CURLEC_WEBHOOK_SECRET" | sed 's/^.* //')
curl -X POST http://localhost:8000/webhooks/curlec -H 'Content-Type: application/json' -H "X-Razorpay-Event-Id: evt_local_$(date +%s)" -H "X-Razorpay-Signature: $SIG" -d "$BODY"
```

The queue worker then confirms the booking. Use real IDs from a test payment, so a later refund through Curlec's test mode also works.

## Things to know

| Fact                              | Effect                                                                          |
| --------------------------------- | ------------------------------------------------------------------------------- |
| A hold lasts 20 minutes           | Availability ignores an expired hold at once, so the scheduler is not needed    |
| Rate limit per token and property | 120 requests a minute, then 429 `rate_limited`                                  |
| Mail                              | Goes to the local mailer in `.env`, so confirmation emails can be checked there |
| Guest records                     | Every booking makes a new guest. Use made-up guest details                      |
| The API allows no browser calls   | A React part calls the microsite's own `pages/api/` route, never estatell       |
