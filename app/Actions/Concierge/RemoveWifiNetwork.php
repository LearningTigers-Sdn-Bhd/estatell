<?php

namespace App\Actions\Concierge;

use App\Models\PropertyWifiNetwork;
use App\Models\User;

class RemoveWifiNetwork
{
    public function __invoke(User $actor, PropertyWifiNetwork $network): void
    {
        activity()->performedOn($network)->causedBy($actor)->event('concierge.wifi-removed')
            ->withProperties(['property_id' => $network->property_id, 'name' => $network->name])->log('Wi-Fi network removed');

        $network->delete();
    }
}
