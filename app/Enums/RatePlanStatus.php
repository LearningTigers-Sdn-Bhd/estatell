<?php

namespace App\Enums;

enum RatePlanStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Archived => __('Archived'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Active => Tone::Success,
            self::Archived => Tone::Neutral,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
