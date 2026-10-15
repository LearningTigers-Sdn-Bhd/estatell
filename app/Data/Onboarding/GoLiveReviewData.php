<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

/**
 * Everything the Review page shows: the rows by phase, the warnings, what still blocks go-live, and the info line.
 */
class GoLiveReviewData extends Data
{
    /**
     * @param  list<GoLiveReviewPhaseData>  $phases
     * @param  list<GoLiveWarningData>  $warnings
     * @param  list<string>  $missing  Labels of the required sections that are not Done yet.
     */
    public function __construct(
        public array $phases,
        public array $warnings,
        public bool $ready,
        public array $missing,
        public int $signInCount,
        public string $firstBusinessDate,
    ) {}
}
