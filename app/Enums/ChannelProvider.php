<?php

namespace App\Enums;

enum ChannelProvider: string
{
    case Channex = 'channex';

    public function label(): string
    {
        return match ($this) {
            self::Channex => __('Channex'),
        };
    }
}
