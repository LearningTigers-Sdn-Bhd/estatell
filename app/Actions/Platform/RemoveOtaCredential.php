<?php

namespace App\Actions\Platform;

use App\Models\PropertyOtaCredential;
use App\Models\User;

class RemoveOtaCredential
{
    /**
     * Delete one saved login. Its booking source and any channel connection stay. The log never holds the login.
     */
    public function __invoke(User $admin, PropertyOtaCredential $credential): void
    {
        $channel = $credential->channel_name ?? $credential->channel->name;

        activity()
            ->performedOn($credential->property)
            ->causedBy($admin)
            ->event('ota_credential.removed')
            ->withProperties(['credential_id' => $credential->id, 'channel' => $channel])
            ->log("OTA login removed for {$channel}");

        $credential->delete();
    }
}
