<?php

namespace App\Data\Onboarding;

use App\Models\PropertyOtaCredential;
use Spatie\LaravelData\Data;

/**
 * One saved OTA list row as onboarding gets it. Saved logins are for the Estatell team only, so the page gets the row's
 * id and channel, and nothing else.
 */
class OnboardingOtaCredentialRowData extends Data
{
    public function __construct(
        public int $id,
        public string $channelLabel,
    ) {}

    /**
     * The channel must be loaded with the row.
     */
    public static function fromModel(PropertyOtaCredential $credential): self
    {
        return new self(
            id: $credential->id,
            channelLabel: $credential->channel_name ?? $credential->channel->name,
        );
    }
}
