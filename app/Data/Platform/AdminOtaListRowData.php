<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Enums\OtaCredentialStatus;
use App\Models\PropertyOtaCredential;
use Spatie\LaravelData\Data;

/**
 * One OTA list row on the admin property page. Never carries the password: Reveal fetches it on demand.
 */
class AdminOtaListRowData extends Data
{
    public function __construct(
        public int $id,
        public string $channel,
        public string $propertyCode,
        public string $username,
        public ?string $marketManagerName,
        public ?string $marketManagerPhone,
        public ?string $marketManagerEmail,
        public BadgeData $status,
        public bool $canMarkProcessed,
        public string $updatedAt,
    ) {}

    /**
     * Expects `channel` loaded. The channel name is typed for Other rows and the channel's own name otherwise.
     */
    public static function fromModel(PropertyOtaCredential $credential): self
    {
        return new self(
            id: $credential->id,
            channel: $credential->channel_name ?? $credential->channel->name,
            propertyCode: $credential->property_code,
            username: $credential->username,
            marketManagerName: $credential->market_manager_name,
            marketManagerPhone: $credential->market_manager_phone,
            marketManagerEmail: $credential->market_manager_email,
            status: BadgeData::fromOtaCredentialStatus($credential->status),
            canMarkProcessed: $credential->status === OtaCredentialStatus::Pending,
            updatedAt: $credential->updated_at->toDateString(),
        );
    }
}
