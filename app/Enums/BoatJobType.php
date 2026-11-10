<?php

namespace App\Enums;

/**
 * What a boat job is for: a boat and crew going out without guests. A fixed list; Other with a note covers the rest.
 * See docs/24-boats/04-sailings.md#jobs.
 */
enum BoatJobType: string
{
    case Diesel = 'diesel';
    case Linen = 'linen';
    case Supplies = 'supplies';
    case Staff = 'staff';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Diesel => __('Diesel'),
            self::Linen => __('Linen'),
            self::Supplies => __('Supplies'),
            self::Staff => __('Staff'),
            self::Maintenance => __('Maintenance'),
            self::Other => __('Other'),
        };
    }

    /** Other says nothing by itself, so staff must say what the job is. */
    public function needsNote(): bool
    {
        return $this === self::Other;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
