<?php

namespace App\Enums;

/**
 * The state of a company's portal login link at one property. Pending waits for the login owner to accept, Active
 * lets them in, Revoked is closed. A company has at most one Pending or Active link.
 */
enum CompanyUserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting to accept'),
            self::Active => __('Active'),
            self::Revoked => __('Revoked'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Active => Tone::Success,
            self::Revoked => Tone::Neutral,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending => in_array($status, [self::Active, self::Revoked], true),
            self::Active => $status === self::Revoked,
            self::Revoked => false,
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Revoked;
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
