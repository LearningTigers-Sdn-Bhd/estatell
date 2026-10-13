# Feature flags

## Purpose

Turn optional capabilities on or off per property, without code changes. The first flag is `ChannelManager`.

## Package

`laravel/pennant` (v1.26, supports Laravel 13), database driver (`features` table).

## Design

- The `Property` model uses the `HasFeatures` trait and implements `FeatureScopeSerializeable`, so the stored scope is `property:{id}` instead of the class name. Feature classes still receive the `Property` model.
- `Feature::resolveScopeUsing()` (in `AppServiceProvider`) returns the `{property}` in the URL, or the signed-in staff user's own property. Admins outside a property have no scope. So every check is per property by default.
- `Feature::discover()` registers every class in `app/Features`.
- Features are class-based, one class per feature, for example `App\Features\ChannelManager`, with a `#[Name]` (`channel-manager`) so a class rename keeps the stored rows.
- A feature's `resolve(Property)` returns the default (off). Later it can resolve from a plan or tier.
- `PlatformFeature` enum lists the features admins manage: `label()`, `description()`, `featureClass()`.
- Routes use `EnsureFeaturesAreActive::using(ChannelManager::class)` (from the Channels module).
- `EnsureFeaturesAreActive::whenInactive()` redirects to an upsell page instead of a 400 response (from the Channels module).
- A feature whose routes must return 404 when off uses its own small middleware instead, because `whenInactive()` is global. First case: `Boats` (see [Boat settings](../24-boats/01-boat-settings.md#feature-flag)).

## Admin UI

- **Features** tab on the admin property page (see [Admin properties](05-admin-properties.md)): one `Switch` per `PlatformFeature`, with an optimistic update that rolls back on failure.
- `PUT /admin/properties/{code}/features/{feature}` (`admin.properties.features.update`), middleware `auth`, `can:admin`, `support.2fa`. `{feature}` binds to `PlatformFeature`, so an unknown feature is `404`. Body: `active` (bool).
- `SetPropertyFeature` Action activates or deactivates and logs `feature.activated` or `feature.deactivated` (subject: property, causer: admin, property `feature`). Setting the same value again does nothing.
- The tab does not show who changed a flag last. The activity log has it; an Activity tab can show it later.

## Rules

- Pennant stores **flags only**. Configuration and credentials live in their own tables.
- Only platform admins activate or deactivate features.
- Activation and deactivation are logged.

## Current features

| Feature          | Default | Unlocks                            |
| ---------------- | ------- | ---------------------------------- |
| `ChannelManager` | Off     | [Channels](../21-channels/) module |
| `Boats`          | Off     | [Boats](../24-boats/) module       |
