<?php

namespace App\Data\Reservations;

use App\Enums\CheckInRoomState;
use Spatie\LaravelData\Data;

/**
 * What the Check in form shows when it opens: tonight's room and whether check-in can assign one, the registration card status, the checks the user may
 * override, the Nationality field when it is missing and Collect now (only for a user with `payment.record`).
 * `auditPending` is the strip shown while the night audit is pending.
 */
class UnitCheckInPreviewData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $countries  Only when `needsNationality`.
     */
    public function __construct(
        public ?string $room,
        public CheckInRoomState $roomState,
        public int $cardsMissing,
        public bool $cardRequired,
        public bool $canCheckInWithoutCard,
        public bool $needsNationality,
        public array $countries,
        public bool $creditExceeded,
        public bool $canOverrideCredit,
        public ?CheckInCollectionData $collection,
        public ?string $error,
        public ?UnitAuditPendingData $auditPending = null,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, CheckInRoomState::None, 0, false, false, false, [], false, false, null, $error);
    }
}
