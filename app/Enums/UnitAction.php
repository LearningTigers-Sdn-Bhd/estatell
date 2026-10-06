<?php

namespace App\Enums;

/**
 * An action of the unit ⋯ menu on the reservation page. Each one opens a form in `FormOverlay`.
 * See docs/18-front-desk/01-check-in-and-check-out.md.
 */
enum UnitAction: string
{
    case Cancel = 'cancel';
    case NoShow = 'no-show';
    case Reinstate = 'reinstate';
    case ChangeDates = 'change-dates';

    public function label(): string
    {
        return match ($this) {
            self::Cancel => __('Cancel unit'),
            self::NoShow => __('Mark no-show'),
            self::Reinstate => __('Reinstate'),
            self::ChangeDates => __('Change dates'),
        };
    }

    public function permission(): PermissionEnum
    {
        return match ($this) {
            self::Cancel, self::NoShow, self::Reinstate, self::ChangeDates => PermissionEnum::ReservationsManage,
        };
    }

    /**
     * Whether a unit in this status can take the action. The same rules the Actions check, so the menu never offers
     * what the Action would refuse.
     */
    public function isOpenFor(UnitStatus $status): bool
    {
        return match ($this) {
            self::Cancel => $status->canTransitionTo(UnitStatus::Cancelled),
            self::NoShow => $status->canTransitionTo(UnitStatus::NoShow),
            self::Reinstate => $status->canTransitionTo(UnitStatus::Confirmed),
            self::ChangeDates => in_array($status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true),
        };
    }
}
