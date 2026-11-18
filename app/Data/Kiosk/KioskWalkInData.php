<?php

namespace App\Data\Kiosk;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * A walk-in search. A night stay has `nights`; a day use one (only the Concierge page sells it) has `hours` and a
 * `startTime` (`H:i`) on the arrival day, and no nights.
 */
class KioskWalkInData extends Data
{
    /** @param list<int> $childAges */
    public function __construct(public CarbonImmutable $arrival, public int $nights, public int $adults, public array $childAges = [], public ?string $startTime = null, public ?int $hours = null) {}

    public function isDayUse(): bool
    {
        return $this->hours !== null && $this->startTime !== null;
    }
}
