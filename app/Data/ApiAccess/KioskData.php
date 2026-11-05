<?php

namespace App\Data\ApiAccess;

use App\Data\Shared\BadgeData;
use App\Enums\KioskStatus;
use App\Enums\Tone;
use App\Models\Kiosk;
use Spatie\LaravelData\Data;

/**
 * A kiosk as staff see it. Never carries a token or a pairing code: those are shown once, when the kiosk is added.
 */
class KioskData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $deviceName,
        public BadgeData $status,
        public ?string $pairedAt,
        public ?string $lastUsedAt,
        public bool $canRevoke,
    ) {}

    public static function fromModel(Kiosk $kiosk): self
    {
        $status = match (true) {
            $kiosk->revoked_at !== null => KioskStatus::Revoked,
            $kiosk->paired_at !== null => KioskStatus::Paired,
            $kiosk->pairing_expires_at?->isFuture() === true => KioskStatus::AwaitingPairing,
            default => KioskStatus::CodeExpired,
        };

        return new self(
            id: $kiosk->id,
            name: $kiosk->name,
            deviceName: $kiosk->device_name,
            status: new BadgeData($status->value, $status->label(), Tone::from($status->tone())),
            pairedAt: $kiosk->paired_at?->toIso8601String(),
            lastUsedAt: $kiosk->last_used_at?->toIso8601String(),
            canRevoke: $status !== KioskStatus::Revoked,
        );
    }
}
