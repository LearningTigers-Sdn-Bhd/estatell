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
| SupportAccessUsed           | On   | On, mandatory | Mail only  | Owner                                    |
| PortalBookingCreated        | On   | On            | Both       | Reservation staff (module 12 permission) |
| PortalBookingCancelled      | On   | On            | Both       | Reservation staff (module 12 permission) |
| PortalCancellationRequested | On   | On, mandatory | Mail only  | Reservation staff (module 12 permission) |
| CompanyLinkInvitation       | On   | On, mandatory | Mail only  | The invited company user                 |
| GoLiveRequested             | On   | On            | Both       | Platform admins, and the partner         |
| GoLiveApproved              | On   | On, mandatory | Mail only  | Owner                                    |
| GoLiveRejected              | On   | On, mandatory | Mail only  | Owner                                    |

`GoLiveRequested` goes to platform admins and the partner, who have no property, so it skips the preference matrix and is always sent by mail and in-app. `CompanyLinkInvitation` does the same for company users, who also have no property: it is a plain notification class, not a `NotificationType` case, and always goes by mail and in-app. `GoLiveApproved` and `GoLiveRejected` are `NotificationType` cases for the owner.

`SupportAccessUsed` is sent when a support session ends (Stop, the 30-minute expiry, a switch to another property, or logout), because it names the duration. The recipient is the property's first active Owner. The data is copied into the notification, because the session is gone when the message is built. It lists the money corrections made in the session (refunds, voids, credit notes, allocation reversals), read from the activity log by the session's support user and time window; the list is left out when there are none.

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
- Property emails name the property in both the HTML and plain-text footer. Partner and platform account emails use estatell alone.
- Invitations show the role or partner name. Owner setup shows the password and verification steps.
- Night audit emails show the existing figures, revenue, payments and meals in tables. Exceptions appear first, followed by the action button. Failed audits separate the error from the recovery instructions.
- Support access emails show session details, optional notes and money corrections. They have no action button.
- Password reset and verification emails use the same layout. Their subjects, expiry rules and links stay unchanged.

Email copy uses short sentences and sentence-case button labels. Invitations use “Hello,” and “Accept invitation”. Owner setup uses a named greeting and “Set up your account”.

Night audit subjects name the business date and result. Their button says “View night audit”. The night audit exception subject also serves as its in-app title. Support email subjects name the property and state that the session ended.

Preview all nine existing email types in the mailbox before signing off the visual review. Rendering tests cover HTML, plain text, footer scope, escaping and authentication links.

## Viewing mail in development

`.env.example` sets `MAIL_MAILER=mailbox`. The dev-only package `redberry/mailbox-for-laravel` stores every outgoing message in `storage/app/mailbox/mailbox.sqlite` (not in Postgres). Read them at `/mailbox`.

- The package is off when `APP_ENV=production`, and the default gate allows viewing outside production only.
- Before using it on staging, define a `viewMailbox` gate that uses the `admin` gate. Captured mail can hold reset tokens.
- Tests are not affected: Laravel's test environment uses the `array` mailer.
