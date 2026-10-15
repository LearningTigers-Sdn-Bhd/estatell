<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * What a guest pays for one night of a Basic rate row, with the room taxes, for a local guest. A Per guest row shows the
 * total for `adults` adults. A price that is blank or not a number gives null.
 */
class OnboardingBasicRatePreviewData extends Data
{
    public function __construct(
        public int $roomTypeId,
        public int $adults,
        public ?string $weekdayTotal,
        public ?string $weekendTotal,
    ) {}
}
