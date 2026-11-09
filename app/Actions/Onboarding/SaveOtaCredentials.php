<?php

namespace App\Actions\Onboarding;

use App\Actions\Reservations\EnsureOtaBookingSource;
use App\Data\Onboarding\OnboardingOtaCredentialData;
use App\Enums\OnboardingStep;
use App\Enums\OtaCredentialStatus;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveOtaCredentials
{
    public function __construct(
        private SkipOnboardingStep $skipStep,
        private UnskipOnboardingStep $unskipStep,
        private EnsureOtaBookingSource $ensureBookingSource,
    ) {}

    /**
     * Save the onboarding OTA lists table in one transaction. Saved rows missing from $savedIds are deleted, and the
     * rest stay as they are, because a saved login is never sent back to this page. New rows are Pending. An empty
     * table counts as Skipped, and any row removes a Skip. Each new row also gets its booking source
     * (EnsureOtaBookingSource), so the channel can be picked on the booking form. Removing a row never removes its
     * source.
     *
     * @param  list<int>  $savedIds
     * @param  list<OnboardingOtaCredentialData>  $rows
     */
    public function __invoke(Property $property, User $actor, array $savedIds, array $rows): void
    {
        DB::transaction(function () use ($property, $actor, $savedIds, $rows): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $property->otaCredentials()->whereNotIn('id', $savedIds)->delete();

            foreach ($rows as $row) {
                $property->otaCredentials()->create([
                    'ota_channel_id' => $row->otaChannelId,
                    'channel_name' => $row->channelName,
                    'property_code' => $row->propertyCode,
                    'username' => $row->username,
                    'password' => $row->password,
                    'market_manager_name' => $row->marketManagerName,
                    'market_manager_phone' => $row->marketManagerPhone,
                    'market_manager_email' => $row->marketManagerEmail,
                    'status' => OtaCredentialStatus::Pending,
                ]);
            }

            $channels = OtaChannel::query()
                ->whereIn('id', array_map(fn (OnboardingOtaCredentialData $row): int => $row->otaChannelId, $rows))
                ->get()
                ->keyBy('id');

            foreach ($rows as $row) {
                $channel = $channels->get($row->otaChannelId);

                if ($channel instanceof OtaChannel) {
                    ($this->ensureBookingSource)($property, $channel, $row->channelName);
                }
            }

            if ($savedIds === [] && $rows === []) {
                ($this->skipStep)($property, OnboardingStep::ChannelManager, $actor);
            } else {
                ($this->unskipStep)($property, OnboardingStep::ChannelManager);
            }
        });
    }
}
