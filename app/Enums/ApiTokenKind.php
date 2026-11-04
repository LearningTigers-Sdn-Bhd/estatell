<?php

namespace App\Enums;

enum ApiTokenKind: string
{
    case Kiosk = 'kiosk';
    case Integration = 'integration';

    public function label(): string
    {
        return match ($this) {
            self::Kiosk => __('Kiosk'),
            self::Integration => __('Integration'),
        };
    }

    /**
     * The scopes a new token of this kind gets, space separated. Integration scopes are chosen when that feature is built.
     */
    public function defaultScopes(): string
    {
        return match ($this) {
            self::Kiosk => 'kiosk:*',
            self::Integration => '',
        };
    }
}
