<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The length of the period the admin Commissions page shows, around a chosen day. Weeks start on Monday.
 * See docs/30-commission-fees/05-platform-pages.md#bookings-tab.
 */
enum CommissionPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('Day'),
            self::Week => __('Week'),
            self::Month => __('Month'),
            self::Quarter => __('Quarter'),
            self::Year => __('Year'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $period): array => ['value' => $period->value, 'label' => $period->label()], self::cases());
    }

    /**
     * The first and last day of the period that holds the day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(CarbonImmutable $day): array
    {
        $day = $day->startOfDay();

        return match ($this) {
            self::Day => [$day, $day],
            self::Week => [$day->startOfWeek(CarbonImmutable::MONDAY), $day->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            self::Month => [$day->startOfMonth(), $day->endOfMonth()->startOfDay()],
            self::Quarter => [$day->startOfQuarter(), $day->endOfQuarter()->startOfDay()],
            self::Year => [$day->startOfYear(), $day->endOfYear()->startOfDay()],
        };
    }
}
