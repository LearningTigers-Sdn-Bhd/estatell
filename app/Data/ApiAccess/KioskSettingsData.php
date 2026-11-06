<?php

namespace App\Data\ApiAccess;

use App\Enums\KioskKeyMode;
use App\Models\PropertySetting;
use Spatie\LaravelData\Data;

/**
 * The kiosk settings of a property as staff edit them.
 */
class KioskSettingsData extends Data
{
    public function __construct(
        public string $keyMode,
        public bool $walkInEnabled,
        public int $idleTimeoutSeconds,
    ) {}

    public static function fromSettings(?PropertySetting $settings): self
    {
        return new self(
            ($settings === null ? KioskKeyMode::Pin : $settings->kiosk_key_mode)->value,
            $settings !== null && $settings->kiosk_walk_in_enabled,
            $settings === null ? 60 : $settings->kiosk_idle_timeout_seconds,
        );
    }
}
