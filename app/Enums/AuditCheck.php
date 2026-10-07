<?php

namespace App\Enums;

/**
 * What the night audit sheet lists before a run. A blocker stops a normal run, and a force overrides it.
 * See docs/20-night-audit/02-readiness-panel.md.
 */
enum AuditCheck: string
{
    case InHouseWithoutRoom = 'in_house_without_room';
    case ArrivalNotCheckedIn = 'arrival_not_checked_in';
    case DepartureNotCheckedOut = 'departure_not_checked_out';
    case FolioOpenAfterCheckOut = 'folio_open_after_check_out';
    case CarriedException = 'carried_exception';

    public function label(): string
    {
        return match ($this) {
            self::InHouseWithoutRoom => __('No room or bed tonight'),
            self::ArrivalNotCheckedIn => __('Not checked in'),
            self::DepartureNotCheckedOut => __('Not checked out'),
            self::FolioOpenAfterCheckOut => __('Folio still open after check-out'),
            self::CarriedException => __('Left from an earlier audit'),
        };
    }

    public function isBlocker(): bool
    {
        return $this === self::InHouseWithoutRoom;
    }

    public function tone(): Tone
    {
        return $this->isBlocker() ? Tone::Danger : Tone::Warning;
    }
}
