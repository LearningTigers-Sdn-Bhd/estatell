<?php

namespace App\Data\NightAudit;

use Spatie\LaravelData\Data;

/**
 * One unit or carried exception under a reservation row of the night audit sheet.
 */
class ReadinessUnitData extends Data
{
    /**
     * @param  list<ReadinessActionData>  $actions
     * @param  string|null  $date  `Y-m-d`, the business date of the exception's audit
     */
    public function __construct(
        public int $unitId,
        public string $guest,
        public string $detail,
        public ?string $reason,
        public array $actions,
        public ?int $exceptionId = null,
        public ?string $date = null,
    ) {}
}
