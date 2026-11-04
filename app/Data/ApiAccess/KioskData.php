<?php

namespace App\Data\ApiAccess;

use App\Data\Shared\BadgeData;
use App\Enums\KioskStatus;
use App\Enums\Tone;
use App\Models\ApiToken;
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

    public static function fromModel(ApiToken $token): self
    {
        $status = match (true) {
            $token->revoked_at !== null => KioskStatus::Revoked,
            $token->token_hash !== null => KioskStatus::Paired,
            $token->pairing_expires_at?->isFuture() === true => KioskStatus::AwaitingPairing,
            default => KioskStatus::CodeExpired,
        };

        return new self(
            id: $token->id,
            name: $token->name,
            deviceName: $token->device_name,
            status: new BadgeData($status->value, $status->label(), Tone::from($status->tone())),
            pairedAt: $token->paired_at?->toIso8601String(),
            lastUsedAt: $token->last_used_at?->toIso8601String(),
            canRevoke: $status !== KioskStatus::Revoked,
        );
    }
}
