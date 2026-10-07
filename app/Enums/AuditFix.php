<?php

namespace App\Enums;

/**
 * A fix the Run Night Audit sheet runs in place. Each fix belongs to one check and calls an existing Action.
 */
enum AuditFix: string
{
    case Assign = 'assign';
    case NoShow = 'no-show';
    case ExtendOneNight = 'extend';

    public function check(): AuditCheck
    {
        return match ($this) {
            self::Assign => AuditCheck::InHouseWithoutRoom,
            self::NoShow => AuditCheck::ArrivalNotCheckedIn,
            self::ExtendOneNight => AuditCheck::DepartureNotCheckedOut,
        };
    }
}
