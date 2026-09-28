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

| Type                       | Mail | In-app        | Opt-out    | Recipients                               |
| -------------------------- | ---- | ------------- | ---------- | ---------------------------------------- |
| NightAuditCompleted        | On   | On            | Both       | `night-audit.receive-report`             |
| NightAuditExceptions       | On   | On            | Both       | `night-audit.receive-report`             |
| NightAuditFailed           | On   | On, mandatory | Mail only  | `night-audit.run`                        |
| RegistrationCardLink       | On   | No            | No (guest) | The guest                                |
| OnboardingReminder         | On   | On            | Both       | Owner                                    |
| DormStrategyChanged        | On   | On            | Both       | Owner                                    |
| SupportAccessUsed          | On   | On, mandatory | Mail only  | Owner                                    |
| AgentBookingCreated        | On   | On            | Both       | Reservation staff (module 12 permission) |
| AgentBookingCancelled      | On   | On            | Both       | Reservation staff (module 12 permission) |
| AgentCancellationRequested | On   | On, mandatory | Mail only  | Reservation staff (module 12 permission) |

## Base class

`PropertyNotification` extends Laravel `Notification`:

- `via()` returns `NotificationPreferences::channelsFor($notifiable, $type)`.
- `shouldSend()` checks again when the queued job runs.
- The constructor calls `afterCommit()`.

## Storage

In-app notifications use the framework `notifications` table. Its `data` column is json and is allowed.
