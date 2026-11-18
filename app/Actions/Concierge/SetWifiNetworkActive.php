<?php

namespace App\Actions\Concierge;

use App\Models\PropertyWifiNetwork;
use App\Models\User;

class SetWifiNetworkActive
{
    /**
     * Show a network to checked-in guests or hide it (staff only). A hidden network stays in the staff list and still
     * counts toward `AddWifiNetwork::MAX`. Nothing is written or logged when it is already in that state.
     */
    public function __invoke(User $actor, PropertyWifiNetwork $network, bool $active): void
    {
        if ($network->active === $active) {
            return;
        }

        $network->active = $active;
        $network->save();

        activity()->performedOn($network)->causedBy($actor)->event($active ? 'concierge.wifi-shown' : 'concierge.wifi-hidden')
            ->withProperties(['property_id' => $network->property_id, 'name' => $network->name])->log($active ? 'Wi-Fi network shown' : 'Wi-Fi network hidden');
    }
}
