<?php

namespace App\Enums;

/**
 * Why a guest request action refused. Sentences are safe to show staff and guests.
 */
enum GuestRequestRefusal: string
{
    case InvalidTransition = 'invalid_transition';
    case StayNotInHouse = 'stay_not_in_house';
    case RoomMismatch = 'room_mismatch';
    case ResolvedOnClosedDate = 'resolved_on_closed_date';
    case NoteRequired = 'note_required';
    case NoTarget = 'no_target';
    case TooManyOpen = 'too_many_open';
    case AssigneeNotAllowed = 'assignee_not_allowed';

    public function label(): string
    {
        return match ($this) {
            self::InvalidTransition => __('This request cannot move to that status.'),
            self::StayNotInHouse => __('A housekeeping or maintenance request needs a guest who is checked in.'),
            self::RoomMismatch => __('The guest does not stay in that room.'),
            self::ResolvedOnClosedDate => __('This request was resolved on a closed business date. Open a new request instead.'),
            self::NoteRequired => __('Write why the request is cancelled.'),
            self::NoTarget => __('Pick a stay or a room.'),
            self::TooManyOpen => __('You already have several open requests. Wait for the team to answer one, or call the front desk.'),
            self::AssigneeNotAllowed => __('That person cannot work on requests.'),
        };
    }
}
