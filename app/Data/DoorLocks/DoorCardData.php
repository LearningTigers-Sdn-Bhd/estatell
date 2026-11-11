<?php

namespace App\Data\DoorLocks;

use App\Data\Shared\BadgeData;
use App\Enums\PasscodeStatus;
use App\Enums\Tone;
use App\Models\DoorCard;
use Spatie\LaravelData\Data;

/**
 * A guest card as staff see it. `cardNumber` is the number TTLock knows the card by, shown so staff can match it to the TTLock app.
 */
class DoorCardData extends Data
{
    public function __construct(
        public int $id,
        public string $lockName,
        public string $cardNumber,
        public BadgeData $status,
        public string $endsAt,
        public ?string $error,
        public bool $canRemove,
    ) {}

    /**
     * Needs the `doorLock` relation loaded.
     */
    public static function fromModel(DoorCard $card): self
    {
        return new self(
            id: $card->id,
            lockName: $card->doorLock->name,
            cardNumber: (string) $card->card_number,
            status: new BadgeData($card->status->value, $card->status->label(), Tone::from($card->status->tone())),
            endsAt: $card->ends_at->toIso8601String(),
            error: $card->error,
            canRemove: $card->status->canTransitionTo(PasscodeStatus::Revoked),
        );
    }
}
