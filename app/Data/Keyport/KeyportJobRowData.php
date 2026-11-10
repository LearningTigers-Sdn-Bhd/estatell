<?php

namespace App\Data\Keyport;

use App\Data\Shared\BadgeData;
use App\Enums\KeyportJobStatus;
use App\Enums\Tone;
use App\Models\KeyportJob;
use Spatie\LaravelData\Data;

/**
 * A key card job as staff see it. Not the wire shape (`DoorLocks\KeyportJobData`). Needs the `doorLock` relation loaded.
 */
class KeyportJobRowData extends Data
{
    public function __construct(
        public int $id,
        public string $typeLabel,
        public BadgeData $status,
        public ?string $lockName,
        public ?string $guest,
        public ?string $message,
        public string $createdAt,
        public bool $canCancel,
        public bool $open,
    ) {}

    public static function fromModel(KeyportJob $job): self
    {
        return new self(
            id: $job->id,
            typeLabel: $job->type->label(),
            status: new BadgeData($job->status->value, $job->status->label(), Tone::from($job->status->tone())),
            lockName: $job->doorLock?->name,
            guest: $job->payload['display']['guest'] ?? null,
            message: self::message($job),
            createdAt: $job->created_at?->toIso8601String() ?? '',
            canCancel: $job->status === KeyportJobStatus::Pending,
            open: ! $job->status->isFinished(),
        );
    }

    /**
     * What staff do next, in plain words, for the outcomes that need a person.
     */
    private static function message(KeyportJob $job): ?string
    {
        return match ($job->status) {
            KeyportJobStatus::Uncertain => __('Keyport could not confirm this card. Open Card tools in Keyport and read it. If it is wrong, make another card.'),
            KeyportJobStatus::Failed => $job->result['error_message'] ?? __('The card was not written. Make a new card.'),
            KeyportJobStatus::Expired => __('Nobody put a card on the reader in time. Make a new card.'),
            default => null,
        };
    }
}
