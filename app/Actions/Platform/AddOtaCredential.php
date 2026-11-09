<?php

namespace App\Actions\Platform;

use App\Actions\Onboarding\UnskipOnboardingStep;
use App\Actions\Reservations\EnsureOtaBookingSource;
use App\Data\Platform\OtaCredentialLoginData;
use App\Enums\OnboardingStep;
use App\Enums\OtaCredentialStatus;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddOtaCredential
{
    public function __construct(
        private EnsureOtaBookingSource $ensureBookingSource,
        private UnskipOnboardingStep $unskipStep,
    ) {}

    /**
     * Save a login the Estatell team received for one channel. The row starts Pending, gets its booking source and
     * removes an onboarding Skip. Returns false when the property already has this channel. The log never holds the
     * login.
     */
    public function __invoke(User $admin, Property $property, OtaChannel $channel, ?string $channelName, OtaCredentialLoginData $login): bool
    {
        return DB::transaction(function () use ($admin, $property, $channel, $channelName, $login): bool {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $taken = $property->otaCredentials()
                ->where('ota_channel_id', $channel->id)
                ->get(['channel_name'])
                ->contains(fn ($saved): bool => Str::lower((string) $saved->channel_name) === Str::lower((string) $channelName));

            if ($taken) {
                return false;
            }

            $credential = $property->otaCredentials()->create([
                'ota_channel_id' => $channel->id,
                'channel_name' => $channelName,
                'property_code' => $login->propertyCode,
                'username' => $login->username,
                'password' => $login->password,
                'market_manager_name' => $login->marketManagerName,
                'market_manager_phone' => $login->marketManagerPhone,
                'market_manager_email' => $login->marketManagerEmail,
                'status' => OtaCredentialStatus::Pending,
            ]);

            ($this->ensureBookingSource)($property, $channel, $channelName);
            ($this->unskipStep)($property, OnboardingStep::ChannelManager);

            $label = $channelName ?? $channel->name;

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('ota_credential.added')
                ->withProperties(['credential_id' => $credential->id, 'channel' => $label])
                ->log("OTA login added for {$label}");

            return true;
        });
    }
}
