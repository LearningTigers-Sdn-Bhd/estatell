<?php

namespace App\Enums;

enum KeyportDeviceStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Online => __('Online'),
            self::Offline => __('Offline'),
            self::Revoked => __('Revoked'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Offline => 'danger',
            self::Revoked => 'neutral',
        };
    }
}
