<?php

use App\Enums\AiKeyStatus;
use App\Models\PlatformSetting;
use App\Models\Property;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Save a hotel OpenAI key on the property, so its AI assistant scopes can be on. Tests never call OpenAI: they fake
 * the agent or the HTTP client.
 */
function giveAiKey(Property $property, ?AiKeyStatus $status = AiKeyStatus::Working, string $key = 'sk-test-hotel-1234'): void
{
    $settings = $property->settings()->firstOrNew();
    $settings->forceFill(['ai_openai_key' => $key, 'ai_key_status' => $status, 'ai_key_checked_at' => $status === null ? null : now()])->save();
    $property->setRelation('settings', $settings);
}

/**
 * Turn the platform switches for the experimental AI places (booking website chat, staff helper) on or off.
 */
function releaseExperimentalAiPlaces(bool $bookingWebsite = true, bool $staffHelper = true): void
{
    PlatformSetting::query()->whereKey(1)->update(['ai_booking_website_enabled' => $bookingWebsite, 'ai_staff_helper_enabled' => $staffHelper]);
    Cache::forget(PlatformSettings::CACHE_KEY);
}
