<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * One new row of the onboarding OTA lists table. A null channel name is used for every channel except Other.
 */
class OnboardingOtaCredentialData extends Data
{
    public function __construct(
        public int $otaChannelId,
        public ?string $channelName,
        public string $propertyCode,
        public string $username,
        public string $password,
        public ?string $marketManagerName,
        public ?string $marketManagerPhone,
        public ?string $marketManagerEmail,
    ) {}
}
