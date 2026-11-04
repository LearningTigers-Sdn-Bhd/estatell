<?php

namespace App\Actions\ApiAccess;

use App\Enums\KioskKeyMode;
use App\Models\Property;
use App\Models\User;

class UpdateKioskSettings
{
    /**
     * Save how the kiosks of a property behave: the key a guest gets, whether walk-ins may book, and the idle time
     * before the screen resets. The settings row is created for a property that has none. The log names the fields
     * that changed.
     */
    public function __invoke(User $actor, Property $property, KioskKeyMode $keyMode, bool $walkInEnabled, int $idleTimeoutSeconds): void
    {
        $settings = $property->settings()->firstOrNew();
        $settings->fill([
            'kiosk_key_mode' => $keyMode,
            'kiosk_walk_in_enabled' => $walkInEnabled,
            'kiosk_idle_timeout_seconds' => $idleTimeoutSeconds,
        ]);
        $changed = array_keys($settings->getDirty());

        if ($changed === []) {
            return;
        }

        $settings->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('api-access.kiosk-settings-updated')
            ->withProperties(['property_id' => $property->id, 'changed' => $changed])
            ->log('Kiosk settings updated');
    }
}
