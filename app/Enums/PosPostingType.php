<?php

namespace App\Enums;

enum PosPostingType: string
{
    case Charge = 'charge';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Charge => __('Charge'),
            self::Void => __('Void'),
        };
    }
}
