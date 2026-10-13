<?php

namespace App\Enums;

enum PartnerStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Invited => __('Invited'),
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Invited => Tone::Info,
            self::Active => Tone::Success,
            self::Suspended => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Invited => [self::Active],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
        }, true);
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
