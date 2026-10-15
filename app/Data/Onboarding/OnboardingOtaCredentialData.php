<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * One row of the OTA lists table as the owner saved it. `id` is set for a row that is already saved. A null password
 * keeps the saved one. A null channel name is used for every channel except Other.
 */
class OnboardingOtaCredentialData extends Data
{
    public function __construct(
        public ?int $id,
        public int $otaChannelId,
        public ?string $channelName,
        public string $propertyCode,
        public string $username,
        public ?string $password,
        public ?string $marketManagerName,
        public ?string $marketManagerPhone,
        public ?string $marketManagerEmail,
    ) {}
}
