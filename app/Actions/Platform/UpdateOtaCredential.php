<?php

namespace App\Actions\Platform;

use App\Data\Platform\OtaCredentialLoginData;
use App\Enums\OtaCredentialStatus;
use App\Models\PropertyOtaCredential;
use App\Models\User;

class UpdateOtaCredential
{
    /**
     * Change the login of one saved row. The channel stays. A null password keeps the saved one. A new password sets the
     * row back to Pending, because the team has to connect it again. The log never holds the login.
     */
    public function __invoke(User $admin, PropertyOtaCredential $credential, OtaCredentialLoginData $login): void
    {
        $passwordChanged = $login->password !== null;

        $credential->update([
            'property_code' => $login->propertyCode,
            'username' => $login->username,
            'market_manager_name' => $login->marketManagerName,
            'market_manager_phone' => $login->marketManagerPhone,
            'market_manager_email' => $login->marketManagerEmail,
            ...($passwordChanged ? ['password' => $login->password, 'status' => OtaCredentialStatus::Pending] : []),
        ]);

        $channel = $credential->channel_name ?? $credential->channel->name;

        activity()
            ->performedOn($credential->property)
            ->causedBy($admin)
            ->event($passwordChanged ? 'ota_credential.password-changed' : 'ota_credential.updated')
            ->withProperties(['credential_id' => $credential->id, 'channel' => $channel])
            ->log($passwordChanged ? "OTA password changed for {$channel}" : "OTA login updated for {$channel}");
    }
}
