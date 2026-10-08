<?php

namespace App\Data\Reports;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Meal counts report: the guests to feed at one meal period on one date. The property names are the
 * column keys of `ReportKey::MealCounts`. `date` is `Y-m-d`. `key` is the date and the meal period id (`2026-10-07:3`).
 */
class MealCountRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $date,
        public string $meal,
        public int $adults,
        public int $children,
        public int $total,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => CarbonImmutable::parse($this->date)->format('j M Y'),
            'meal' => $this->meal,
            'adults' => (string) $this->adults,
            'children' => (string) $this->children,
            'total' => (string) $this->total,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'meal' => $this->meal,
            'adults' => $this->adults,
            'children' => $this->children,
            'total' => $this->total,
        ];
    }
}
