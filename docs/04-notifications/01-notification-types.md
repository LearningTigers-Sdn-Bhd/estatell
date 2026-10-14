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

`SupportAccessUsed` is sent when a support session ends (Stop, the 30-minute expiry, a switch to another property, or logout), because it names the duration. The recipient is the property's first active Owner. The data is copied into the notification, because the session is gone when the message is built. It lists the money corrections made in the session (refunds, voids, credit notes, allocation reversals), read from the activity log by the session's support user and time window; the list is left out when there are none.

## Base class

`PropertyNotification` extends Laravel `Notification`:

- `via()` returns `NotificationPreferences::channelsFor($notifiable, $type)`.
- `shouldSend()` checks again when the queued job runs.
- The constructor calls `afterCommit()`.

## Storage

In-app notifications use the framework `notifications` table. Its `data` column is json and is allowed.

## Viewing mail in development

`.env.example` sets `MAIL_MAILER=mailbox`. The dev-only package `redberry/mailbox-for-laravel` stores every outgoing message in `storage/app/mailbox/mailbox.sqlite` (not in Postgres). Read them at `/mailbox`.

- The package is off when `APP_ENV=production`, and the default gate allows viewing outside production only.
- Before using it on staging, define a `viewMailbox` gate that uses the `admin` gate. Captured mail can hold reset tokens.
- Tests are not affected: Laravel's test environment uses the `array` mailer.
