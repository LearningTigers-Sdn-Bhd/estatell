<?php

namespace App\Enums;

enum PosStationMode: string
{
    case Kds = 'kds';
    case BrowserPrint = 'browser_print';
    case PosportPrinter = 'posport_printer';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Kds => __('Kitchen display'),
            self::BrowserPrint => __('Browser docket print'),
            self::PosportPrinter => __('Posport printer'),
            self::Both => __('Display and print'),
        };
    }

    public function showsOnDisplay(): bool
    {
        return $this === self::Kds || $this === self::Both;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
