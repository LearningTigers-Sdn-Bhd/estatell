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
| `status`              | `MailStatus`: `sent`, `failed`, `suppressed`.                                                    |
| `provider_message_id` | The id the mail provider returned. Empty when the send failed. Webhooks use it later.            |
| `error`               | The provider message when the send failed.                                                       |
| `sent_at`             | When the attempt happened.                                                                       |

`sent` means the provider accepted the message. It does not mean the guest received it.

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

## Later

Delivery webhooks (step 2) move `status` on to `delivered`, `bounced` or `complained` by `provider_message_id`.
