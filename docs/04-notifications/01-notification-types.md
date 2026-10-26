# Notification types

## Purpose

Define every notification once, with its channels, defaults and recipients.

## Enums

`NotificationType`, with methods:

- `channels()`: supported channels.
- `defaultEnabled(NotificationChannel $channel)`.
- `canOptOut(NotificationChannel $channel)`.
- `recipientPermission()`.

`NotificationChannel`: Mail, Database.

| Type                        | Mail | In-app        | Opt-out    | Recipients                               |
| --------------------------- | ---- | ------------- | ---------- | ---------------------------------------- |
| NightAuditCompleted         | On   | On            | Both       | `night-audit.receive-report`             |
| NightAuditExceptions        | On   | On            | Both       | `night-audit.receive-report`             |
| NightAuditFailed            | On   | On, mandatory | Mail only  | `night-audit.run`                        |
| RegistrationCardLink        | On   | No            | No (guest) | The guest                                |
| OnboardingReminder          | On   | On            | Both       | Owner                                    |
| DormStrategyChanged         | On   | On            | Both       | Owner                                    |
| SupportAccessUsed           | No   | On, mandatory | No         | Owner                                    |
| PortalBookingCreated        | On   | On            | Both       | Reservation staff (module 12 permission) |
| PortalBookingCancelled      | On   | On            | Both       | Reservation staff (module 12 permission) |
| PortalCancellationRequested | On   | On, mandatory | Mail only  | Reservation staff (module 12 permission) |
| CompanyLinkInvitation       | On   | On, mandatory | Mail only  | The invited company user                 |
| GoLiveRequested             | On   | On            | Both       | Platform admins, and the partner         |
| GoLiveApproved              | On   | On, mandatory | Mail only  | Owner                                    |
| GoLiveRejected              | On   | On, mandatory | Mail only  | Owner                                    |
| ChannelBookingReceived      | On   | On            | Both       | Reservation staff (module 12 permission) |
| ChannelBookingCancelled     | On   | On            | Both       | Reservation staff (module 12 permission) |
| ChannelRemovalBlocked       | On   | On, mandatory | Mail only  | The staff member who archived            |
| ChannelHelpRequested        | On   | On, mandatory | Mail only  | Owner                                    |

`PortalBookingCreated`, `PortalBookingCancelled` and `PortalCancellationRequested` are built (`PortalReservationNotification`, sent by `NotifyReservationStaff` after the save). The recipients are the active staff of the property with `reservations.manage`; each message names the company and the reference and links to the reservation. The matrix check constraints list the three types.

`GoLiveRequested` goes to platform admins and the partner, who have no property, so it skips the preference matrix and is always sent by mail and in-app. `CompanyLinkInvitation` does the same for company users, who also have no property: it is a plain notification class, not a `NotificationType` case, and always goes by mail and in-app. `GoLiveApproved` and `GoLiveRejected` are `NotificationType` cases for the owner.

`ChannelBookingReceived` and `ChannelBookingCancelled` are built (`ChannelReservationNotification`, sent by `NotifyChannelReservationStaff` after the channel booking is saved). The recipients are the active staff of the property with `reservations.manage`; each message names the channel and the reference and links to the reservation. The matrix check constraints list the two types. `ChannelRemovalBlocked` is sent to the staff member who archived a room type or rate plan (or unlinked room types) when an OTA still used it at the channel manager, so it was not done; the estatell team has the same news as an alert. The matrix check constraints list it. `ChannelHelpRequested` is planned with the [Channels](../21-channels/) module and is sent when an admin asks the owner for help with a channel.

`SupportAccessUsed` (label "Money correction by support") is in-app only. It is sent once for each money correction (refund, void, credit note, allocation reversal) that support makes while viewing a property as its staff, right when the activity log entry is created (`NotifySupportMoneyCorrection`). The recipient is the property's first active Owner. It names the support user, the partner, the action, the amount and the reference. Starting and ending a session send nothing. The enum value stays `support_access_used`, so the check constraints are unchanged.

## Base class

`PropertyNotification` extends Laravel `Notification`:

- `via()` returns `NotificationPreferences::channelsFor($notifiable, $type)`.
- `shouldSend()` checks again when the queued job runs.
- The constructor calls `afterCommit()`.

## Storage

In-app notifications use the framework `notifications` table. Its `data` column is json and is allowed.

## Email layout

All existing emails use the shared stationery layout in `resources/views/vendor/notifications/email.blade.php` and the published `laravel-mail` components.

- The header uses a text-only **Estatell** wordmark matching the supplied reference: heavy sans-serif lettering, a capital E and dark forest green. Email-safe fonts approximate the reference until the exact font or logo asset is supplied.
- The layout uses an olive canvas, a white body, an emerald top rule and full-width action buttons.
- Email CSS uses inline, email-safe colour fallbacks and system fonts. It does not depend on Tailwind, web fonts or CSS variables.
- Dark mode: the layout declares `color-scheme: light dark`. Its `<style>` block holds a `@media (prefers-color-scheme: dark)` block with `!important` overrides (dark olive page and card, light text, light emerald button with dark text, darker warning and error panels), plus `[data-ogsc]` and `[data-ogsb]` rules for Outlook.com. The theme CSS keeps the light colours. Dark rules live in `resources/views/vendor/mail/html/layout.blade.php` because the theme CSS is inlined and media queries there are lost. Gmail apps may still recolour a message on their own.
- Property emails name the property in both the HTML and plain-text footer. Partner and platform account emails use estatell alone.
- Invitations show the role or partner name. Owner setup shows the password and verification steps.
- Night audit emails show the existing figures, revenue, payments and meals in tables. Exceptions appear first, followed by the action button. Failed audits separate the error from the recovery instructions.
- Support access emails show session details, optional notes and money corrections. They have no action button.
- Password reset and verification emails use the same layout. Their subjects, expiry rules and links stay unchanged.

Email copy uses short sentences and sentence-case button labels. Invitations use “Hello,” and “Accept invitation”. Owner setup uses a named greeting and “Set up your account”.

Night audit subjects name the business date and result. Their button says “View night audit”. The night audit exception subject also serves as its in-app title. Support email subjects name the property and state that the session ended.

Preview all nine existing email types in the mailbox before signing off the visual review. Rendering tests cover HTML, plain text, footer scope, escaping and authentication links.

## Sending mail in production

Production sends through MillionSend, a Resend-compatible email API. Set `MAIL_MAILER=millionsend`.

| Variable                     | Meaning                                                                                     |
| ---------------------------- | ------------------------------------------------------------------------------------------- |
| `MAIL_MAILER`                | `millionsend` in production. `mailbox` in development. `array` in tests.                    |
| `MILLIONSEND_BASE_URL`       | Origin of the MillionSend API. No default, so a missing value fails loudly.                 |
| `MILLIONSEND_API_KEY`        | Bearer key for the API.                                                                     |
| `MILLIONSEND_WEBHOOK_SECRET` | Signing secret for delivery webhooks. See the [Mail log](03-mail-log.md#delivery-webhooks). |
| `MAIL_FROM_ADDRESS`          | An address on a domain verified in MillionSend.                                             |

`App\Services\Notifications\MillionSendTransport` is a mail transport that `AppServiceProvider` registers with `Mail::extend('millionsend')`. It posts each message to `{base_url}/emails` with the key as a bearer token. The body carries `from`, `to`, `cc`, `bcc`, `reply_to`, `subject`, `html`, `text` and base64 `attachments`.

- All recipients suppressed (HTTP 422, `all_recipients_suppressed`): the address bounced or complained before. The transport logs the recipient count and skips the send. It does not fail the queued notification.
- Any other API error throws a `TransportException`. The queued job fails and Horizon retries it.
- The app log never holds addresses or subjects. Each attempt is recorded in the [Mail log](03-mail-log.md).

## Viewing mail in development

`.env.example` sets `MAIL_MAILER=mailbox`. The dev-only package `redberry/mailbox-for-laravel` stores every outgoing message in `storage/app/mailbox/mailbox.sqlite` (not in Postgres). Read them at `/mailbox`.

- The package is off when `APP_ENV=production`, and the default gate allows viewing outside production only.
- Before using it on staging, define a `viewMailbox` gate that uses the `admin` gate. Captured mail can hold reset tokens.
- Tests are not affected: Laravel's test environment uses the `array` mailer.
