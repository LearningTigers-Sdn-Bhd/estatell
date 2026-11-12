<?php

namespace App\Enums;

/**
 * How the tables of a property's PDFs look. The shared PDF layout draws the style; the Printing page preview draws the
 * same look. See docs/02-property/05-printing-settings.md#table-style.
 */
enum PdfTableStyle: string
{
    case Minimal = 'minimal';
    case Lined = 'lined';
    case Shaded = 'shaded';

    public function label(): string
    {
        return match ($this) {
            self::Minimal => __('Minimal'),
            self::Lined => __('Lined'),
            self::Shaded => __('Shaded'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Minimal => __('A thin line between rows'),
            self::Lined => __('A border around every cell'),
            self::Shaded => __('A grey header and every other row tinted'),
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
        ], self::cases());
    }
}
