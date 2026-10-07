<?php

namespace App\Enums;

enum PdfFont: string
{
    case Standard = 'standard';
    case Chinese = 'chinese';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('Standard (Latin, Malay)'),
            self::Chinese => __('Chinese'),
        };
    }

    /**
     * The CSS font family dompdf draws with. Noto Sans SC also covers Latin.
     */
    public function fontFamily(): string
    {
        return match ($this) {
            self::Standard => 'DejaVu Sans',
            self::Chinese => 'Noto Sans SC',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
