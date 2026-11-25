<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * The fields of a menu schedule form: its name, days (a bitmask, Monday 1 to Sunday 64), the daily window as `HH:MM`, the
 * dates it applies between, and the categories and items it covers.
 */
class PosMenuScheduleInputData extends Data
{
    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $itemIds
     */
    public function __construct(
        public string $name,
        public int $daysOfWeek,
        public string $startTime,
        public string $endTime,
        public array $categoryIds = [],
        public array $itemIds = [],
        public ?string $effectiveFrom = null,
        public ?string $effectiveUntil = null,
        public ?int $mealPeriodId = null,
        public bool $active = true,
    ) {}
}
