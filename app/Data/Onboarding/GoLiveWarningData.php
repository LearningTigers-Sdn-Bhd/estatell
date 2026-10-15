<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * A problem that does not block go-live, with the section that fixes it.
 */
class GoLiveWarningData extends Data
{
    public function __construct(
        public string $step,
        public string $stepLabel,
        public string $message,
        public string $url,
    ) {}
}
