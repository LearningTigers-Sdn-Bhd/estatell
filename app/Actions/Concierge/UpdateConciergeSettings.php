<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\User;

class UpdateConciergeSettings
{
    /**
     * Save how the Concierge page of a property behaves: whether a guest with no booking may book a room from the lobby
     * page, whether a guest may ask for a refund after check-out, and the house rules shown in the property guide. The
     * settings row is created for a property that has none. Nothing is written or logged when nothing changed.
     */
    public function __invoke(User $actor, Property $property, bool $walkInEnabled, bool $refundRequestsEnabled, ?string $houseRules): void
    {
        $settings = $property->settings()->firstOrNew();
        $settings->fill([
            'concierge_walk_in_enabled' => $walkInEnabled,
            'concierge_refund_requests_enabled' => $refundRequestsEnabled,
            'house_rules' => filled($houseRules) ? trim((string) $houseRules) : null,
        ]);
        $changed = array_keys($settings->getDirty());

        if ($changed === []) {
            return;
        }

        $settings->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('concierge.settings-updated')
            ->withProperties(['property_id' => $property->id, 'changed' => $changed])
            ->log('Concierge settings updated');
    }
}
