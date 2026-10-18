<?php

namespace App\Enums;

/**
 * The period presets of the dashboard Performance tab. The value is the `period` URL value. A whole past month is
 * picked with `month=Y-m` and has no preset. See docs/18-front-desk/06-dashboard-performance.md#period.
 */
enum PerformancePreset: string
{
    case SevenDays = '7d';
    case ThirtyDays = '30d';
    case Month = 'month';
    case LastMonth = 'last-month';
    case TwelveMonths = '12m';

    public function label(): string
    {
        return match ($this) {
            self::SevenDays => __('Last 7 days'),
            self::ThirtyDays => __('Last 30 days'),
            self::Month => __('This month'),
            self::LastMonth => __('Last month'),
            self::TwelveMonths => __('12 months'),
        };
    }

    /**
     * The preset used when the URL has no valid value.
     */
    public static function default(): self
    {
        return self::Month;
    }

    /**
     * Whether the trend is grouped by month instead of by day.
     */
    public function groupsByMonth(): bool
    {
        return $this === self::TwelveMonths;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $preset): array => ['value' => $preset->value, 'label' => $preset->label()],
            self::cases(),
        );
    }
}
