<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The Overdue guest choice of the Check out form, sent while the night audit is pending and the unit was due to leave
 * on the business date. `nightDate` (`Y-m-d`) is the night "They stayed last night" adds, `nightTotal` its price with tax
 * and add-ons, `lines` the rows it posts (shown as added now) and `balanceIfStayed` the unit folio balance after that
 * night. When the form asks for `stayed_last_night`, the preview's own balance already includes the night.
 */
class UnitOverdueData extends Data
{
    public function __construct(
        public string $nightDate,
        public string $nightTotal,
        /** @var list<UnitPendingLineData> */
        public array $lines,
        public string $balanceIfStayed,
        public bool $stayed,
    ) {}
}
