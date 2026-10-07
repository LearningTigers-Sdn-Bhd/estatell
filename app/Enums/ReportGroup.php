<?php

namespace App\Enums;

/**
 * How a report groups its rows, chosen with `?group` in the URL. Only the reports that list their groups in
 * `ReportKey::groups()` take it. See docs/22-reports/01-core-reports.md.
 */
enum ReportGroup: string
{
    case Day = 'day';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('Day'),
            self::Month => __('Month'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $group): array => ['value' => $group->value, 'label' => $group->label()],
            self::cases(),
        );
    }
}
