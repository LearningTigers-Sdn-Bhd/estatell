<?php

namespace App\Actions\Concierge;

use App\Models\PropertyWifiNetwork;
use App\Models\User;

class UpdateWifiNetwork
{
    /**
     * Change a network's name, password, note and whether it is the main one. A new main network replaces the old one.
     * Nothing is written or logged when nothing changed. The log names the changed fields, never the password.
     */
    public function __invoke(User $actor, PropertyWifiNetwork $network, string $name, ?string $password, ?string $note, bool $primary): void
    {
        $network->fill([
            'name' => trim($name),
            'password' => filled($password) ? trim((string) $password) : null,
            'note' => filled($note) ? trim((string) $note) : null,
            'primary_network' => $primary,
        ]);

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
