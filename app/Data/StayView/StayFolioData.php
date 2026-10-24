<?php

namespace App\Data\StayView;

use Spatie\LaravelData\Data;

/**
 * The newest posted lines of a reservation's folios and its balance, for the peek sheet.
 */
class StayFolioData extends Data
{
    /**
     * @param  list<StayFolioLineData>  $lines  Newest first.
     * @param  bool  $hasMore  Whether older lines are not listed.
     */
    public function __construct(
        public string $balance,
        public array $lines,
        public bool $hasMore,
    ) {}
}
