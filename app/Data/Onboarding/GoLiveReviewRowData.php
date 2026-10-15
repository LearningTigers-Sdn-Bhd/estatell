<?php

namespace App\Data\Onboarding;

use App\Enums\StepState;
use Spatie\LaravelData\Data;

/**
 * One section on the Review page: its state, a one-line summary built on the server, and the link to act on. `flagged` is
set when an admin flagged the section while sending a request back.
 */
class GoLiveReviewRowData extends Data
{
    /**
     * @param  list<string>  $details  Extra lines for the admin review only. Empty on the owner's Review.
     */
    public function __construct(
        public string $value,
        public string $label,
        public StepState $state,
        public bool $isRequired,
        public bool $skipped,
        public string $summary,
        public string $actionLabel,
        public string $actionUrl,
        public bool $flagged = false,
        public array $details = [],
    ) {}
}
