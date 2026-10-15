<?php

namespace App\Data\Onboarding;

use App\Models\OtaChannel;
use Spatie\LaravelData\Data;

/**
 * One channel the owner can pick in the OTA lists table.
 */
class OnboardingOtaChannelData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isOther,
    ) {}

    public static function fromModel(OtaChannel $channel): self
    {
        return new self(
            id: $channel->id,
            name: $channel->name,
            isOther: $channel->is_other,
        );
    }
}
