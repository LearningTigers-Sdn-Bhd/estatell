<?php

namespace App\Actions\Platform;

use App\Models\PropertyOtaCredential;
use App\Models\User;

class RevealOtaCredential
{
    /**
     * Return the saved password for the estatell team and log who looked at it. The log never holds the password.
     */
    public function __invoke(User $admin, PropertyOtaCredential $credential): string
    {
        $channel = $credential->channel_name ?? $credential->channel->name;

        activity()
            ->performedOn($credential->property)
            ->causedBy($admin)
            ->event('ota_credential.revealed')
            ->withProperties(['credential_id' => $credential->id, 'channel' => $channel])
            ->log("OTA password revealed for {$channel}");

        return $credential->password;
    }
}
