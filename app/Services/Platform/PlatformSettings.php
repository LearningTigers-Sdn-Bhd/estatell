<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached read of the single platform settings row.
 *
 * The cache holds the raw column values, not the model, because `cache.serializable_classes`
 * is false and objects cannot be unserialized from the cache.
 */
class PlatformSettings
{
    public const string CACHE_KEY = 'platform_settings';

    public function current(): PlatformSetting
    {
        /** @var array<string, mixed> $attributes */
        $attributes = Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => PlatformSetting::query()->findOrFail(1)->getAttributes(),
        );

        return (new PlatformSetting)->newFromBuilder($attributes);
    }
}
