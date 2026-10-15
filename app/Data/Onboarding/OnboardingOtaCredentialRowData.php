<?php

namespace App\Data\Onboarding;

use App\Data\Shared\BadgeData;
use App\Models\PropertyOtaCredential;
use Spatie\LaravelData\Data;

/**
 * One saved OTA list row as the page gets it. The password is never sent. A saved row always has one, so `hasPassword`
 * is true. `status` is shown on Settings → OTA lists only.
 */
class OnboardingOtaCredentialRowData extends Data
{
    public function __construct(
        public int $id,
        public int $otaChannelId,
        public string $channelLabel,
        public ?string $channelName,
        public string $propertyCode,
        public string $username,
        public bool $hasPassword,
        public ?string $marketManagerName,
        public ?string $marketManagerPhone,
        public ?string $marketManagerEmail,
        public BadgeData $status,
    ) {}

    /**
     * The channel must be loaded with the row.
     */
    public static function fromModel(PropertyOtaCredential $credential): self
    {
        return new self(
            id: $credential->id,
            otaChannelId: $credential->ota_channel_id,
            channelLabel: $credential->channel->name,
            channelName: $credential->channel_name,
            propertyCode: $credential->property_code,
            username: $credential->username,
            hasPassword: true,
            marketManagerName: $credential->market_manager_name,
            marketManagerPhone: $credential->market_manager_phone,
            marketManagerEmail: $credential->market_manager_email,
            status: BadgeData::fromOtaCredentialStatus($credential->status),
        );
    }
}
