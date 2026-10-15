<?php

namespace App\Actions\Onboarding;

use App\Data\Onboarding\OnboardingOtaCredentialData;
use App\Enums\OnboardingStep;
use App\Enums\OtaCredentialStatus;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveOtaCredentials
{
    public function __construct(
        private SkipOnboardingStep $skipStep,
        private UnskipOnboardingStep $unskipStep,
    ) {}

    /**
     * Save the OTA lists table in one transaction. Saved rows missing from the submit are deleted. A row keeps its
     * saved password when the password is null. A row whose channel or channel name changes is saved again as a new
     * row, so the old channel slot is free first. New rows are Pending. An empty table counts as Skipped, and saving
     * at least one row removes a Skip.
     *
     * @param  list<OnboardingOtaCredentialData>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows): void
    {
        DB::transaction(function () use ($property, $actor, $rows): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->otaCredentials()->get()->keyBy('id');

            $property->otaCredentials()
                ->whereNotIn('id', array_filter(array_map(fn (OnboardingOtaCredentialData $row): ?int => $row->id, $rows)))
                ->delete();

            foreach ($rows as $row) {
                $credential = $row->id === null ? null : $saved->get($row->id);

                if ($credential !== null && ! $this->keepsSlot($credential, $row)) {
                    $credential->delete();
                }
            }

            foreach ($rows as $row) {
                $credential = $row->id === null ? null : $saved->get($row->id);
                $attributes = [
                    'ota_channel_id' => $row->otaChannelId,
                    'channel_name' => $row->channelName,
                    'property_code' => $row->propertyCode,
                    'username' => $row->username,
                    'market_manager_name' => $row->marketManagerName,
                    'market_manager_phone' => $row->marketManagerPhone,
                    'market_manager_email' => $row->marketManagerEmail,
                ];

                if ($credential !== null && $this->keepsSlot($credential, $row)) {
                    $credential->update($row->password === null ? $attributes : [...$attributes, 'password' => $row->password]);

                    continue;
                }

                $property->otaCredentials()->create([
                    ...$attributes,
                    'password' => $row->password ?? $credential?->password,
                    'status' => OtaCredentialStatus::Pending,
                ]);
            }

            if ($rows === []) {
                ($this->skipStep)($property, OnboardingStep::ChannelManager, $actor);
            } else {
                ($this->unskipStep)($property, OnboardingStep::ChannelManager);
            }
        });
    }

    /**
     * Whether the saved row keeps its channel and channel name. The unique index is on the same two values, ignoring
     * case for the name.
     */
    private function keepsSlot(PropertyOtaCredential $credential, OnboardingOtaCredentialData $row): bool
    {
        return $credential->ota_channel_id === $row->otaChannelId
            && Str::lower((string) $credential->channel_name) === Str::lower((string) $row->channelName);
    }
}
