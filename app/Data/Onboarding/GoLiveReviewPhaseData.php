<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

class GoLiveReviewPhaseData extends Data
{
    /**
     * @param  list<GoLiveReviewRowData>  $rows
     */
    public function __construct(
        public string $value,
        public string $label,
        public array $rows,
    ) {}
}
