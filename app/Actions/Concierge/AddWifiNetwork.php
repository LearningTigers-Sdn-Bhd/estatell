<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\PropertyWifiNetwork;
use App\Models\User;

class AddWifiNetwork
{
    /** Networks a property can list, so the page stays short. */
    public const int MAX = 10;

    /**
     * Add a Wi-Fi network to the end of the property's list. Returns null, writing nothing, when the property already lists
     * `MAX` networks. The log names the network, never its password.
     */
    public function __invoke(User $actor, Property $property, string $name, ?string $password, ?string $note): ?PropertyWifiNetwork
    {
        if ($property->wifiNetworks()->count() >= self::MAX) {
            return null;
        }

        $network = $property->wifiNetworks()->create([
            'name' => trim($name),
            'password' => filled($password) ? trim((string) $password) : null,
            'note' => filled($note) ? trim((string) $note) : null,
            'position' => (int) $property->wifiNetworks()->max('position') + 1,
        ]);

        activity()->performedOn($network)->causedBy($actor)->event('concierge.wifi-added')
            ->withProperties(['property_id' => $property->id, 'name' => $network->name])->log('Wi-Fi network added');

        return $network;
    }
}
