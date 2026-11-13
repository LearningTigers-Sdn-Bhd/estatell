<?php

namespace App\Actions\Concierge;

use App\Models\PropertyWifiNetwork;
use App\Models\User;

class UpdateWifiNetwork
{
    /**
     * Change whether a network is the main one and whether guests see it. A new main network replaces the old one. Nothing
     * is written or logged when nothing changed.
     */
    public function __invoke(User $actor, PropertyWifiNetwork $network, bool $active, bool $primary): void
    {
        $network->fill(['active' => $active, 'primary_network' => $primary]);

        if (! $network->isDirty()) {
            return;
        }

        if ($primary) {
            PropertyWifiNetwork::query()->where('property_id', $network->property_id)->whereKeyNot($network->id)->update(['primary_network' => false]);
        }

        $changed = array_keys($network->getDirty());
        $network->save();

        activity()->performedOn($network)->causedBy($actor)->event('concierge.wifi-updated')
            ->withProperties(['property_id' => $network->property_id, 'name' => $network->name, 'changed' => $changed])->log('Wi-Fi network updated');
    }
}
