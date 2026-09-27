# Platform settings

## Purpose

One place for platform-wide switches that admins can change without a deploy.

## Data model

```
platform_settings    id (always 1), require_go_live_approval (bool, default false),
                     default_dorm_strategy (DormStrategy, default BedAsUnit), timestamps
```

- A **single-row table with typed columns**. Not key-value, not jsonb.
- New settings are new columns with a migration and a default.

## Services and Actions

- `PlatformSettings` service: cached read of the single row.
- `UpdatePlatformSettings` Action: admin only, logged.

## Settings

| Column                     | Effect                                                                                                                      |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `require_go_live_approval` | When true, `GoLive` moves a property to `PendingApproval` instead of `Live`. See [Go live](../17-onboarding/03-go-live.md). |
| `default_dorm_strategy`    | Default Channex strategy for Bed room types. See [Dorm strategies](../21-channels/03-dorm-strategies.md).                   |
