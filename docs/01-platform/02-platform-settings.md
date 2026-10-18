# Platform settings

## Purpose

One place for platform-wide switches that admins can change without a deploy.

## Data model

```
platform_settings    id (always 1), require_go_live_approval (bool, default false),
                     default_dorm_strategy (DormStrategy, default BedAsUnit),
                     media_disk (MediaDisk, default Local), timestamps
```

- A **single-row table with typed columns**. Not key-value, not jsonb.
- New settings are new columns with a migration and a default.

## Services and Actions

- `PlatformSettings` service: cached read of the single row (cache key `platform_settings`). The cache holds raw column values, not the model, because `cache.serializable_classes` is false.
- `UpdatePlatformSettings` Action: admin only. Saves only the submitted section; omitted fields stay unchanged, including a concurrent change from another tab. Logs changed columns (old and new) to the activity log. Saving without changes writes no entry. Clears the cache.
- The migration inserts row 1. CHECK constraints keep `id = 1` and valid enum values.

## Routes and page

| Method + URL                   | Name                             | Middleware                   |
| ------------------------------ | -------------------------------- | ---------------------------- |
| `GET /admin/settings`          | `admin.settings.edit`            | auth, can:admin              |
| `PUT /admin/settings`          | `admin.settings.update`          | auth, can:admin, support.2fa |
| `GET /admin/settings/channels` | `admin.settings.channels`        | auth, can:admin              |
| `PUT /admin/settings/channels` | `admin.settings.channels.update` | auth, can:admin, support.2fa |
| `GET /admin/settings/storage`  | `admin.settings.storage`         | auth, can:admin              |
| `PUT /admin/settings/storage`  | `admin.settings.storage.update`  | auth, can:admin, support.2fa |

Page: `pages/admin/settings/edit.tsx`, in the admin layout. General, Channel manager and File storage are `TabLinks`, each with an icon and its own URL. Tabs scroll horizontally on narrow screens. The page header has no extra bottom margin; a 16px gap separates it from the tabs.

The server sends only the selected tab's setting and choices. Each tab has its own form and a right-aligned **Save changes** button. Saving stays on that tab. A section cannot write fields from another section, even if they are added to its request. All admin save controls remain visible; `support.2fa` enforces the configured requirement on every write route.

### Selection cards

- Each choice uses a whole-card `FieldLabel`, a radio indicator, an icon, a title and a description. The existing radio primitives provide keyboard navigation and checked, focus, invalid and disabled states. Cards stay in one column, including mobile.
- **General:** Automatic or Admin approval. The radio sends an explicit false or true value; omitting the selection is a validation error. Turning approval off leaves existing pending requests pending.
- **Channel manager:** Bed as unit or Native dorm. Labels and descriptions come from `DormStrategy`. Native dorm is disabled pending verification and is rejected on save. Existing Native dorm defaults are not changed by saving another section.
- **File storage:** This server or Cloudflare R2. Labels and descriptions come from `MediaDisk`. R2 shows **Not set up** until configured and is rejected on save while unavailable.

## Settings

| Column                     | Effect                                                                                                                                                                                                                                                                                                                         |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `require_go_live_approval` | When true, owners **Send for approval** and an admin approves or sends it back. The checkbox help: "Owners send their setup for review. You approve it or send it back from Properties, under Waiting for approval." Switching it off leaves pending requests pending. See [Go live](../17-onboarding/03-go-live.md#approval). |
| `default_dorm_strategy`    | Default Channex strategy for Bed room types. See [Dorm strategies](../21-channels/03-dorm-strategies.md).                                                                                                                                                                                                                      |
| `media_disk`               | Where new uploads are stored. See [Media storage](#media-storage).                                                                                                                                                                                                                                                             |

## Media storage

Admins choose where new uploads (ID scans, signatures, photos, PDFs) are stored. The pattern follows aroundthat.

| `MediaDisk` | Label         | Disk    | Available when                                                                 |
| ----------- | ------------- | ------- | ------------------------------------------------------------------------------ |
| `Local`     | This server   | `local` | Always. Private folder `storage/app/private`.                                  |
| `R2`        | Cloudflare R2 | `r2`    | `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` are set |

- `MediaDisk` has `label()`, `options()`, `diskName()` and `isConfigured()`. R2 keys live in the environment only, never in the database.
- The storage card shows R2 as disabled with **Not set up** until its keys are set. `UpdatePlatformSettingsRequest` rejects an unconfigured disk with a validation error.
- Media collections read the disk from `PlatformSettings` when a file is added. This wiring is built: the ID scans of [Identity documents](../11-guests/02-identity-documents.md) are the first media collection, and later collections do the same. Each `media` row stores its own disk, so switching affects only new uploads. Old files stay readable.
- Both disks are private. Files are shown through temporary URLs: presigned URLs on R2, Laravel's `serve` option on `local`.
- Production needs the `media` volume for `Local`. See [Production environment](../deployment/production_environment.md).
