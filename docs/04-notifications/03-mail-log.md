# Mail log

## Purpose

Answer "did that email go out?" from inside the app. Every message that the mailer sends leaves one row, so a platform admin can find it by recipient or subject.

## Rows

Table `mail_events`. One row per send attempt. A queued job that retries leaves one row for each try.

| Column                | Meaning                                                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------ |
| `property_id`         | The recipient's property when the recipient is a staff user. Empty for platform mail.            |
| `notification_type`   | The notification class, for example `GoLiveApproved`. Empty for mail that is not a notification. |
| `recipient`           | The first `to` address. Encrypted (CipherSweet) with an exact-match blind index.                 |
| `recipient_count`     | How many `to` addresses the message had.                                                         |
| `subject`             | Plain text. The body is never stored.                                                            |
| `status`              | `MailStatus`: `sent`, `delivered`, `bounced`, `complained`, `failed`, `suppressed`.              |
| `provider_message_id` | The id the mail provider returned. Empty when the send failed. Webhooks use it later.            |
| `error`               | The provider message when the send failed.                                                       |
| `sent_at`             | When the attempt happened.                                                                       |

`sent` means the provider accepted the message. It does not mean the guest received it. The delivery webhooks move it on (see below).

## How rows are written

- A `MessageSending` listener in `AppServiceProvider` copies the notification class from the mail data into the `X-Estatell-Notification` header. A `MessageSent` listener then calls the `RecordMailEvent` Action for every message, whichever mailer sent it. No notification class changes.
- `MillionSendTransport` adds two headers to the original message after the call: `X-Estatell-Mail-Status` (`suppressed` when every recipient was suppressed) and `X-Estatell-Provider-Message-Id`. The listener reads them. The headers are not sent to the provider.
- A failed send throws before `MessageSent` fires, so the transport calls `RecordMailEvent` with `failed` and then throws as before. The queued job still retries.

## Admin page

`/admin/mail-log`, platform admins only (`can:admin`). It is read-only, so it has no `support.2fa` middleware.

- Status counts, a status filter, a date range, search and pagination in the URL.
- Search by an exact email address (blind index) or by part of the subject.
- A detail page shows the full row.
- The sidebar item is "Mail log".

## Retention

`PruneMailEvents` deletes rows older than 90 days (`MailEvent::RETENTION_DAYS`). It runs daily at 03:45 from `routes/console.php`, on one server. Running it twice deletes nothing more. The encrypted recipient and its blind index rows go with the row.

## Privacy

A mail log keeps recipient addresses for 90 days. Addresses are encrypted at rest. Subjects can name a property or a person, so only platform admins see the page.

## Delivery webhooks

MillionSend tells estatell what happened to a message after it accepted it. The endpoint is `POST /webhooks/millionsend`. It uses Standard Webhooks signatures, the Resend and Svix format. To verify: the exact payload fields, until a real delivery is seen. The code reads `type` and `data.email_id`, and the bounce reason from `data.bounce.message`.

| Event              | Row status   | Note                                         |
| ------------------ | ------------ | -------------------------------------------- |
| `email.delivered`  | `delivered`  | The recipient's server accepted the message. |
| `email.bounced`    | `bounced`    | The reason goes in `error`.                  |
| `email.complained` | `complained` | The recipient marked it as spam.             |
| `email.failed`     | `failed`     | The provider could not send it.              |
| `email.suppressed` | `suppressed` | The address is on the suppression list.      |
| Any other event    | none         | Answered 200 and ignored.                    |

The row is found by `provider_message_id`. `MailStatus::canTransitionTo()` decides the move:

- `sent` can become any other status.
- `delivered` can become `bounced` or `complained`, because a later bounce or complaint is real news.
- `bounced`, `complained`, `failed` and `suppressed` do not change.

So a repeated event or an event that arrives late changes nothing.

### Signature check

`VerifyMillionSendSignature` runs before the controller:

- It needs the `webhook-id`, `webhook-timestamp` and `webhook-signature` headers.
- The secret is `MILLIONSEND_WEBHOOK_SECRET`: `whsec_` followed by base64. Decode the part after the prefix to get the key.
- The signature is base64 HMAC-SHA256 of `{id}.{timestamp}.{raw body}`. The header holds one or more space-separated `v1,<signature>` values, and any match passes. That covers key rotation.
- A timestamp more than five minutes off is a replay and gets 401.
- With no secret set, every webhook gets 401.

### Responses

- 200 for a valid signature, even when the event is ignored or the message id is unknown. MillionSend would retry any other answer. The endpoint may also receive events for mail that other apps on the same team sent.
- 401 for a bad or missing signature.

### Setup

Your team registers the endpoint with MillionSend (`POST /webhooks` on its API) for the events in the table, and sets the secret in Coolify as `MILLIONSEND_WEBHOOK_SECRET`. The URL is `https://{app domain}/webhooks/millionsend`.
