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
    case Method = 'method';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('Day'),
            self::Month => __('Month'),
            self::Method => __('By method'),
            self::Staff => __('By staff'),
        };
    }

    /**
     * The line under the title of a PDF: "By day", "By month", "By method" or "By staff".
     */
    public function caption(): string
    {
        return match ($this) {
            self::Day, self::Month => __('By :group', ['group' => mb_strtolower($this->label())]),
            self::Method, self::Staff => $this->label(),
        };
    }

    /**
     * @param  list<self>  $groups  The groups to list, from `ReportKey::groups()`.
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $groups): array
    {
        return array_map(
            fn (self $group): array => ['value' => $group->value, 'label' => $group->label()],
            $groups,
        );
    }
}
