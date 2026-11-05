<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Enums\KioskKeyMode;
use App\Enums\Locale;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /config`: what the kiosk shows before a guest starts. Languages are the ones Estatell has terms for.
 *
 * @property Property $resource
 */
class KioskConfigResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->resource->settings;

        return [
            'property_name' => $this->resource->name,
            'languages' => array_map(fn (Locale $locale): string => $locale->value, Locale::cases()),
            'key_mode' => ($settings === null ? KioskKeyMode::Pin : $settings->kiosk_key_mode)->value,
            'walk_in_enabled' => $settings !== null && $settings->kiosk_walk_in_enabled,
            'idle_timeout_seconds' => $settings === null ? 60 : $settings->kiosk_idle_timeout_seconds,
            'currency' => $settings?->currency->getCurrencyCode(),
        ];
    }
}
