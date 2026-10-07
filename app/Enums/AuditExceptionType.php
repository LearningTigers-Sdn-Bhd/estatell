<?php

namespace App\Enums;

/**
 * Why one unit or add-on needs a person after a night audit. The exception row holds the refusal code or the error.
 */
enum AuditExceptionType: string
{
    case MissedArrival = 'missed_arrival';
    case OverdueDeparture = 'overdue_departure';
    case RoomCharge = 'room_charge';
    case AddOn = 'add_on';
    case UnexpectedError = 'unexpected_error';

    public function label(): string
    {
        return match ($this) {
            self::MissedArrival => __('Missed arrival not resolved'),
            self::OverdueDeparture => __('Overdue departure not resolved'),
            self::RoomCharge => __('Room charge not posted'),
            self::AddOn => __('Add-on not posted'),
            self::UnexpectedError => __('Unexpected error'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::UnexpectedError => Tone::Danger,
            default => Tone::Warning,
        };
    }
}
