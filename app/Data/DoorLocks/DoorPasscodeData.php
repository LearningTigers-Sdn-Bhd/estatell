<?php

namespace App\Data\DoorLocks;

use App\Data\Shared\BadgeData;
use App\Enums\PasscodeStatus;
use App\Enums\Tone;
use App\Models\DoorPasscode;
use Spatie\LaravelData\Data;

/**
 * A PIN as staff see it. The code is shown because staff read it out to the guest. `revokeNote` is the "still works on the lock" note of a revoked PIN, shown only while the PIN's window is open.
 */
class DoorPasscodeData extends Data
{
    public function __construct(
        public int $id,
        public string $lockName,
        public string $label,
        public string $kindLabel,
        public BadgeData $status,
        public ?string $code,
        public string $startsAt,
        public string $endsAt,
        public ?string $error,
        public ?string $revokeNote,
        public bool $canRevoke,
        public bool $canRetry,
    ) {}

    /**
     * Needs the `doorLock` relation loaded.
     */
    public static function fromModel(DoorPasscode $passcode): self
    {
        return new self(
            id: $passcode->id,
            lockName: $passcode->doorLock->name,
            label: $passcode->label,
            kindLabel: $passcode->kind->label(),
            status: new BadgeData($passcode->status->value, $passcode->status->label(), Tone::from($passcode->status->tone())),
            code: $passcode->code,
            startsAt: $passcode->starts_at->toIso8601String(),
            endsAt: $passcode->ends_at->toIso8601String(),
            error: $passcode->error,
            revokeNote: $passcode->status === PasscodeStatus::Revoked && $passcode->ends_at->isFuture() ? $passcode->revoke_note : null,
            canRevoke: $passcode->status->canTransitionTo(PasscodeStatus::Revoked),
            canRetry: $passcode->status === PasscodeStatus::Failed && $passcode->ends_at->isFuture(),
        );
    }
}
