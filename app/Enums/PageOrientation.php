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
