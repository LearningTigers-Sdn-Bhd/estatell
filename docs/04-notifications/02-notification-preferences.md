# Notification preferences and opt-out

## Purpose

Some properties do not want emails, for example night audit reports. Owners can switch a notification off for the whole property, and each user can opt out for themselves.

## Data model

```
property_notification_settings    property_id, type, channel, enabled
                                  unique (property_id, type, channel)
user_notification_preferences     user_id, type, channel, enabled
                                  unique (user_id, type, channel)
```

- **Sparse rows.** A row exists only when a setting differs from the enum default.

## Resolution

A channel is sent only if all are true:

1. The type supports the channel.
2. The property setting (row or default) is enabled.
3. The user preference (row or default) is enabled.

If the property turns a channel off, no user receives it. Mandatory channels (`canOptOut` false) are always sent.

## Services and Actions

- `NotificationPreferences::channelsFor(User $user, NotificationType $type): array`
- `UpdatePropertyNotificationSetting` (permission `notification.manage`)
- `UpdateUserNotificationPreference`

Both Actions delete the row when the new value equals the default.

## UI

- Settings > Notifications: type x channel matrix for the property. Mandatory cells are locked.
- Profile > Notifications: the same matrix for the user. Cells disabled by the property show "Disabled by property".

## Lessons from wastays

`notification_configs` stored `channels` and `settings` as jsonb. Use the two sparse tables and enum defaults instead.
