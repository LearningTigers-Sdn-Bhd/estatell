<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * What the onboarding board adds to a property row: the step the owner is on, how far along they are, and when
 * they last made progress.
 */
class OnboardingBoardRowData extends Data
{
    public function __construct(
        public string $currentStep,
        public int $percent,
        public string $lastActivity,
    ) {}
}
