<?php

namespace App\Enums;

enum PosProvider: string
{
    case Loyverse = 'loyverse';
    case GenericApi = 'generic_api';

    public function label(): string
    {
        return match ($this) {
            self::Loyverse => __('Loyverse'),
            self::GenericApi => __('POS API'),
        };
    }
}
