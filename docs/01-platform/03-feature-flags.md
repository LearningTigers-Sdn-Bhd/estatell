# Feature flags

## Purpose

Turn optional capabilities on or off per property, without code changes. The first flag is `ChannelManager`.

## Package

`laravel/pennant` (v1.26, supports Laravel 13), database driver (`features` table).

## Design

- The `Property` model uses the `HasFeatures` trait.
- `Feature::resolveScopeUsing()` returns the current property, so every check is per property by default.
- Features are class-based, one class per feature, for example `App\Features\ChannelManager`.
- A feature's `resolve()` returns the default (off). Later it can resolve from a plan or tier.
- Routes use `EnsureFeaturesAreActive::using(ChannelManager::class)`.
- `EnsureFeaturesAreActive::whenInactive()` redirects to an upsell page instead of a 400 response.

## Rules

- Pennant stores **flags only**. Configuration and credentials live in their own tables.
- Only platform admins activate or deactivate features.
- Activation and deactivation are logged.

## Current features

| Feature          | Default | Unlocks                            |
| ---------------- | ------- | ---------------------------------- |
| `ChannelManager` | Off     | [Channels](../21-channels/) module |
