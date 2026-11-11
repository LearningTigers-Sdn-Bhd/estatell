<?php

namespace App\Enums;

/**
 * Why a housekeeping Action did nothing. The Actions return it instead of throwing.
 */
enum HousekeepingRefusal: string
{
    case OutOfService = 'out_of_service';
    case InvalidTransition = 'invalid_transition';
    case CleaningInProgress = 'cleaning_in_progress';
    case CleaningNotStarted = 'cleaning_not_started';

    public function message(string $room): string
    {
        return match ($this) {
            self::OutOfService => __('Room :room is out of service.', ['room' => $room]),
            self::InvalidTransition => __('Room :room cannot move to that status.', ['room' => $room]),
            self::CleaningInProgress => __('Room :room is already being cleaned. Tap Done first.', ['room' => $room]),
            self::CleaningNotStarted => __('Room :room has no cleaning in progress. Tap Start first.', ['room' => $room]),
        };
    }
}
