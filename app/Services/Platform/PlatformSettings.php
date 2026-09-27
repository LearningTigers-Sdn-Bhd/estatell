<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached read of the single platform settings row.
 */
class PlatformSettings
{
    public const string CACHE_KEY = 'platform_settings';

    public function current(): PlatformSetting
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): PlatformSetting => PlatformSetting::query()->findOrFail(1));
    }
}
