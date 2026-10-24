<?php

namespace App\Enums;

enum PasscodeStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Failed => __('Failed'),
            self::Revoked => __('Revoked'),
            self::Expired => __('Expired'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Active => 'success',
            self::Failed => 'danger',
            self::Revoked, self::Expired => 'neutral',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Active, self::Failed, self::Revoked], true),
            self::Failed => in_array($next, [self::Pending, self::Revoked], true),
            self::Active => in_array($next, [self::Revoked, self::Expired], true),
            self::Revoked, self::Expired => false,
        };
    }
}
