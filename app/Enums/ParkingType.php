<?php

namespace App\Enums;

/**
 * How guests park: themselves, with a valet, or either.
 */
enum ParkingType: string
{
    case SelfParking = 'self';
    case Valet = 'valet';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::SelfParking => __('Self parking'),
            self::Valet => __('Valet'),
            self::Both => __('Self parking and valet'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
