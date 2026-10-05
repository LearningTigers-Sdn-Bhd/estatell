<?php

namespace App\Data\Catalogues;

use Spatie\LaravelData\Data;

/** A sample stay with what the policy charges in each case, before and after tax. */
class RoomRevenuePolicyPreviewData extends Data
{
    /** @param list<RoomRevenuePolicyPreviewLineData> $lines */
    public function __construct(
        public string $sampleNight,
        public int $sampleNights,
        public array $lines,
    ) {}
}
