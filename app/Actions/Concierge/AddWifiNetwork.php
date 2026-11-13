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
     * `MAX` networks. A new main network replaces the old one. The log names the network, never its password.
     */
    public function __invoke(User $actor, Property $property, string $name, ?string $password, ?string $note, bool $active = true, bool $primary = false): ?PropertyWifiNetwork
    {
        if ($property->wifiNetworks()->count() >= self::MAX) {
            return null;
        }

        if ($primary) {
            $property->wifiNetworks()->update(['primary_network' => false]);
        }

        $network = $property->wifiNetworks()->create([
            'name' => trim($name),
            'password' => filled($password) ? trim((string) $password) : null,
            'note' => filled($note) ? trim((string) $note) : null,
            'active' => $active,
            'primary_network' => $primary,
            'position' => (int) $property->wifiNetworks()->max('position') + 1,
        ]);

        activity()->performedOn($network)->causedBy($actor)->event('concierge.wifi-added')
            ->withProperties(['property_id' => $property->id, 'name' => $network->name])->log('Wi-Fi network added');

        return $network;
    }
}
