<?php

namespace App\Enums;

/**
 * An action of the unit ⋯ menu on the reservation page. Each one opens a form in `FormOverlay`.
 * See docs/18-front-desk/01-check-in-and-check-out.md.
 */
enum UnitAction: string
{
    case CheckIn = 'check-in';
    case CheckOut = 'check-out';
    case LateCheckOut = 'late-check-out';
    case ChangeDates = 'change-dates';
    case Cancel = 'cancel';
    case NoShow = 'no-show';
    case Reinstate = 'reinstate';
    case MoveRoom = 'move-room';
    case UndoCheckIn = 'undo-check-in';
    case UndoCheckOut = 'undo-check-out';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => __('Check in'),
            self::CheckOut => __('Check out'),
            self::LateCheckOut => __('Late check-out'),
            self::Cancel => __('Cancel unit'),
            self::NoShow => __('Mark no-show'),
            self::Reinstate => __('Reinstate'),
            self::ChangeDates => __('Change dates'),
            self::MoveRoom => __('Move room'),
            self::UndoCheckIn => __('Undo check-in'),
            self::UndoCheckOut => __('Undo check-out'),
        };
    }

    public function permission(): PermissionEnum
    {
        return match ($this) {
            self::CheckIn => PermissionEnum::FrontDeskCheckIn,
            self::CheckOut, self::LateCheckOut => PermissionEnum::FrontDeskCheckOut,
            self::UndoCheckIn => PermissionEnum::FrontDeskUndoCheckIn,
            self::UndoCheckOut => PermissionEnum::FrontDeskUndoCheckOut,
            self::Cancel, self::NoShow, self::Reinstate, self::ChangeDates, self::MoveRoom => PermissionEnum::ReservationsManage,
        };
    }

    /**
     * Whether a unit in this status can take the action. The same rules the Actions check, so the menu never offers
     * what the Action would refuse.
     */
    public function isOpenFor(UnitStatus $status): bool
    {
        return match ($this) {
            self::CheckIn => $status->canTransitionTo(UnitStatus::CheckedIn),
            self::CheckOut => $status->canTransitionTo(UnitStatus::CheckedOut),
            self::LateCheckOut => $status === UnitStatus::CheckedIn,
            self::UndoCheckIn => $status->canUndoCheckIn(),
            self::UndoCheckOut => $status->canUndoCheckOut(),
            self::Cancel => $status->canTransitionTo(UnitStatus::Cancelled),
            self::NoShow => $status->canTransitionTo(UnitStatus::NoShow),
            self::Reinstate => $status->canTransitionTo(UnitStatus::Confirmed),
            self::ChangeDates, self::MoveRoom => in_array($status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true),
        };
    }
}
