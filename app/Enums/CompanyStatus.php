<?php

namespace App\Enums;

/**
 * Whether a company can be picked on new bookings. Companies are never deleted.
 */
enum CompanyStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Active => Tone::Success,
            self::Suspended => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $this !== $status;
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
