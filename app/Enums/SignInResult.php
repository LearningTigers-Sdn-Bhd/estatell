<?php

namespace App\Enums;

/**
 * How one sign-in attempt ended. See docs/01-platform/07-sign-in-log.md.
 */
enum SignInResult: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TwoFactorFailed = 'two_factor_failed';
    case LockedOut = 'locked_out';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => __('Signed in'),
            self::Failed => __('Wrong email or password'),
            self::TwoFactorFailed => __('Wrong two-factor code'),
            self::LockedOut => __('Locked out'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Succeeded => Tone::Success,
            self::Failed, self::TwoFactorFailed => Tone::Warning,
            self::LockedOut => Tone::Danger,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $result): array => ['value' => $result->value, 'label' => $result->label()],
            self::cases(),
        );
    }
}
