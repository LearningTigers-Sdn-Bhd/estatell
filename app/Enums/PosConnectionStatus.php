<?php

namespace App\Enums;

enum PosConnectionStatus: string
{
    case Connected = 'connected';
    case Error = 'error';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => __('Connected'),
            self::Error => __('Needs attention'),
            self::Disconnected => __('Disconnected'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Error => 'danger',
            self::Disconnected => 'neutral',
        };
    }
}
