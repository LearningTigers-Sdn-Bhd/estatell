<?php

namespace App\Enums;

enum RegistrationCardFieldGroup: string
{
    case Guest = 'guest';
    case Stay = 'stay';
    case Prices = 'prices';

    public function label(): string
    {
        return match ($this) {
            self::Guest => __('Guest details'),
            self::Stay => __('Stay details'),
            self::Prices => __('Prices'),
        };
    }
}
