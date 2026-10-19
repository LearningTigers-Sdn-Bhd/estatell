<?php

namespace App\Enums;

/**
 * What one row of the old folio page copies: a room night, a charge item, a tax or a payment from the old system.
 * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
 */
enum OldFolioRowKind: string
{
    case Room = 'room';
    case Charge = 'charge';
    case Tax = 'tax';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Room => __('Room night'),
            self::Charge => __('Charge'),
            self::Tax => __('Tax'),
            self::Payment => __('Payment'),
        };
    }
}
