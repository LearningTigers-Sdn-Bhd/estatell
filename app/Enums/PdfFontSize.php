<?php

namespace App\Enums;

enum PdfFontSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    public function label(): string
    {
        return match ($this) {
            self::Small => __('Small'),
            self::Medium => __('Medium'),
            self::Large => __('Large'),
        };
    }

    /**
     * The body text size in points. Small text is 1 pt less, headings 2 pt more and the title 7 pt more.
     */
    public function points(): float
    {
        return match ($this) {
            self::Small => 8.0,
            self::Medium => 9.0,
            self::Large => 10.5,
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
            'description' => __(':points pt body text', ['points' => $case->points()]),
        ], self::cases());
    }
}
