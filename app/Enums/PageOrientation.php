<?php

namespace App\Enums;

/**
 * The paper orientation of a printed report. See docs/22-reports/01-core-reports.md.
 */
enum PageOrientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';

    public function label(): string
    {
        return match ($this) {
            self::Portrait => __('Portrait'),
            self::Landscape => __('Landscape'),
        };
    }

    /**
     * A report table with more columns than this prints tight: 1 pt smaller text, 2 pt cell padding and the date over
     * the time. A4 portrait has about two-thirds of the landscape width (515 pt against 760 pt).
     */
    public function wideTableColumns(): int
    {
        return match ($this) {
            self::Portrait => 6,
            self::Landscape => 9,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $orientation): array => ['value' => $orientation->value, 'label' => $orientation->label()],
            self::cases(),
        );
    }
}
