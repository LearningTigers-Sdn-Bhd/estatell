<?php

namespace App\Data\DoorLocks;

use App\Data\Shared\BadgeData;
use App\Enums\PasscodeStatus;
use App\Enums\Tone;
use App\Models\DoorCard;
use Spatie\LaravelData\Data;

/**
 * A card as a row of the Door locks page, like `DoorPasscodeData` is for a PIN. `cardNumber` is the number TTLock knows the card by,
 * shown so staff can match it to the TTLock app.
 */
class DoorCardRowData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public string $lockName,
        public string $cardNumber,
        public BadgeData $status,
        public string $startsAt,
        public string $endsAt,
        public ?string $error,
        public bool $canRemove,
        public ?string $guestName = null,
        public ?string $reference = null,
        public ?string $room = null,
    ) {}

    /**
     * Needs the `doorLock` relation loaded.
     */
    public static function fromModel(DoorCard $card): self
    {
        return new self(
            ...CredentialStay::of($card),
            id: $card->id,
            label: $card->label,
            lockName: $card->doorLock->name,
            cardNumber: (string) $card->card_number,
            status: new BadgeData($card->status->value, $card->status->label(), Tone::from($card->status->tone())),
            startsAt: $card->starts_at->toIso8601String(),
            endsAt: $card->ends_at->toIso8601String(),
            error: $card->error,
            canRemove: $card->status->canTransitionTo(PasscodeStatus::Revoked),
        );
    }
}
