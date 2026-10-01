<?php

namespace App\Enums;

enum IdentityDocumentType: string
{
    case MyKad = 'mykad';
    case Passport = 'passport';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MyKad => __('MyKad'),
            self::Passport => __('Passport'),
            self::Other => __('Other'),
        };
    }

    /**
     * A normalized number as people read it: a MyKad is shown as 900101-14-1234.
     */
    public function formatNumber(string $number): string
    {
        if ($this === self::MyKad && preg_match('/^(\d{6})(\d{2})(\d{4})$/', $number, $parts) === 1) {
            return "{$parts[1]}-{$parts[2]}-{$parts[3]}";
        }

        return $number;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
