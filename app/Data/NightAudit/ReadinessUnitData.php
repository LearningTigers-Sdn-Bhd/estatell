<?php

namespace App\Data\NightAudit;

use Spatie\LaravelData\Data;

/**
 * One unit under a reservation row of the night audit sheet, shown when the row is opened.
 */
class ReadinessUnitData extends Data
{
    /**
     * @param  list<ReadinessActionData>  $actions
     */
    public function __construct(
        public int $unitId,
        public string $guest,
        public string $detail,
        public ?string $reason,
        public array $actions,
    ) {}
}
