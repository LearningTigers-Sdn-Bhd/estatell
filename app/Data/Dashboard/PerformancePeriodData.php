<?php

namespace App\Data\Dashboard;

use App\Enums\PerformancePreset;
use App\Services\Dashboard\PerformancePeriod;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The period of the Performance tab: the range, the range it is compared with, and what the period bar offers.
 */
class PerformancePeriodData extends Data
{
    /**
     * @param  string|null  $preset  The `period` URL value. Null when a month is picked.
     * @param  string|null  $month  The picked month, `Y-m`. Null for a preset.
     * @param  string  $from  `Y-m-d`.
     * @param  string  $to  `Y-m-d`, the business date or earlier.
     * @param  string  $previousFrom  `Y-m-d`.
     * @param  string  $previousTo  `Y-m-d`.
     * @param  string  $groupBy  `day` or `month`: the step of the trend.
     * @param  list<array{value: string, label: string}>  $presets
     * @param  list<string>  $months  Whole past months the picker offers, newest first, `Y-m`.
     */
    public function __construct(
        public ?string $preset,
        public ?string $month,
        public string $from,
        public string $to,
        public string $previousFrom,
        public string $previousTo,
        public string $groupBy,
        public array $presets,
        public array $months,
    ) {}

    public static function fromPeriod(PerformancePeriod $period, CarbonImmutable $businessDate, ?CarbonImmutable $earliest): self
    {
        return new self(
            preset: $period->preset?->value,
            month: $period->month,
            from: $period->from->toDateString(),
            to: $period->to->toDateString(),
            previousFrom: $period->previousFrom->toDateString(),
            previousTo: $period->previousTo->toDateString(),
            groupBy: $period->groupsByMonth ? 'month' : 'day',
            presets: PerformancePreset::options(),
            months: PerformancePeriod::pickableMonths($businessDate, $earliest),
        );
    }
}
