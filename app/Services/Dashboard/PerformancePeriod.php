<?php

namespace App\Services\Dashboard;

use App\Enums\PerformancePreset;
use Carbon\CarbonImmutable;

/**
 * The range of the dashboard Performance tab and the range it is compared with. Both are built from the business
 * date, never the calendar date, and both end on or before it. A bad URL value falls back to This month.
 * See docs/18-front-desk/06-dashboard-performance.md#period.
 */
final readonly class PerformancePeriod
{
    public function __construct(
        public ?PerformancePreset $preset,
        public ?string $month,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
        public bool $groupsByMonth,
    ) {}

    /**
     * @param  CarbonImmutable|null  $earliest  The first date with data. A picked month before its month is not offered.
     */
    public static function fromRequest(mixed $period, mixed $month, CarbonImmutable $businessDate, ?CarbonImmutable $earliest = null): self
    {
        $picked = is_string($month) ? self::pickedMonth($month, $businessDate, $earliest) : null;

        if ($picked !== null) {
            return self::wholeMonth(null, $picked);
        }

        $preset = is_string($period) ? PerformancePreset::tryFrom($period) : null;

        return self::forPreset($preset ?? PerformancePreset::default(), $businessDate);
    }

    public static function forPreset(PerformancePreset $preset, CarbonImmutable $date): self
    {
        $date = $date->startOfDay();

        return match ($preset) {
            PerformancePreset::SevenDays => self::lastDays($preset, $date, 7),
            PerformancePreset::ThirtyDays => self::lastDays($preset, $date, 30),
            PerformancePreset::Month => self::monthToDate($date),
            PerformancePreset::LastMonth => self::wholeMonth($preset, $date->startOfMonth()->subMonthNoOverflow()),
            PerformancePreset::TwelveMonths => new self(
                $preset,
                null,
                $date->startOfMonth()->subMonthsNoOverflow(11),
                $date,
                $date->startOfMonth()->subMonthsNoOverflow(11)->subYear(),
                $date->subYearNoOverflow(),
                true,
            ),
        };
    }

    /**
     * The whole months a user can pick, newest first: from the month of the earliest data to last month.
     *
     * @return list<string> `Y-m`
     */
    public static function pickableMonths(CarbonImmutable $businessDate, ?CarbonImmutable $earliest): array
    {
        if ($earliest === null) {
            return [];
        }

        $months = [];
        $last = $businessDate->startOfMonth()->subMonthNoOverflow();

        for ($month = $last; $month->gte($earliest->startOfMonth()); $month = $month->subMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * The first day of the month, or null when the value is not a whole past month with data.
     */
    private static function pickedMonth(string $value, CarbonImmutable $businessDate, ?CarbonImmutable $earliest): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1 || $earliest === null) {
            return null;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $value);

        if ($month === null || $month->gte($businessDate->startOfMonth()) || $month->lt($earliest->startOfMonth())) {
            return null;
        }

        return $month;
    }

    private static function lastDays(PerformancePreset $preset, CarbonImmutable $date, int $days): self
    {
        $from = $date->subDays($days - 1);

        return new self($preset, null, $from, $date, $from->subDays($days), $from->subDay(), false);
    }

    /**
     * The 1st to the business date, against the 1st to the same day number of last month. A day number that last month
     * does not have (31 Oct to September) ends on the last day of that month.
     */
    private static function monthToDate(CarbonImmutable $date): self
    {
        $from = $date->startOfMonth();
        $previousFrom = $from->subMonthNoOverflow();

        return new self(
            PerformancePreset::Month,
            null,
            $from,
            $date,
            $previousFrom,
            $previousFrom->addDays($date->day - 1)->min($previousFrom->endOfMonth()->startOfDay()),
            false,
        );
    }

    private static function wholeMonth(?PerformancePreset $preset, CarbonImmutable $month): self
    {
        $previousFrom = $month->subMonthNoOverflow();

        return new self(
            $preset,
            $preset === null ? $month->format('Y-m') : null,
            $month,
            $month->endOfMonth()->startOfDay(),
            $previousFrom,
            $previousFrom->endOfMonth()->startOfDay(),
            false,
        );
    }
}
