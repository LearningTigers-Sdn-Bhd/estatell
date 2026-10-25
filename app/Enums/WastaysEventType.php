<?php

namespace App\Enums;

/**
 * What happened in one wastays row of a replayed day. The rank is the tie order inside one timestamp: create, check-in,
 * charge, payment, reversal, stay change, early check-out, check-out. See docs/migration-plan/grand-eastern-hotel.md "Day loop".
 */
enum WastaysEventType: string
{
    case Create = 'create';
    case CheckIn = 'check_in';
    case Charge = 'charge';
    case Adjustment = 'adjustment';
    case Repair = 'repair';
    case Payment = 'payment';
    case Deposit = 'deposit';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case FolioMovement = 'folio_movement';
    case StayChange = 'stay_change';
    case EarlyDeparture = 'early_departure';
    case CheckOut = 'check_out';

    public function rank(): int
    {
        return match ($this) {
            self::Create => 0,
            self::CheckIn => 1,
            self::Charge, self::Adjustment, self::Repair => 2,
            self::Payment, self::Deposit, self::Refund => 3,
            self::Reversal, self::FolioMovement => 4,
            self::StayChange => 5,
            self::EarlyDeparture => 6,
            self::CheckOut => 7,
        };
    }
}
