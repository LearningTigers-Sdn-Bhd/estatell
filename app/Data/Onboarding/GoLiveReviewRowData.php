<?php

namespace App\Data\Onboarding;

use App\Enums\StepState;
use Spatie\LaravelData\Data;

/**
 * One section on the Review page: its state, a one-line summary built on the server, and the link to act on.
 */
class GoLiveReviewRowData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public StepState $state,
        public bool $isRequired,
        public bool $skipped,
        public string $summary,
        public string $actionLabel,
        public string $actionUrl,
    ) {}
}
