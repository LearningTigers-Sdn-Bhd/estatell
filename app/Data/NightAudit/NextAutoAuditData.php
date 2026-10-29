<?php

namespace App\Data\NightAudit;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * When the automatic audit closes the current business date, on the property's clock.
 */
class NextAutoAuditData extends Data
{
    /**
     * @param  string  $day  `Y-m-d`.
     * @param  string  $time  `H:i`.
     */
    public function __construct(
        public string $day,
        public string $time,
    ) {}

    public static function fromMoment(CarbonImmutable $dueAt): self
    {
        return new self(day: $dueAt->toDateString(), time: $dueAt->format('H:i'));
    }
}
