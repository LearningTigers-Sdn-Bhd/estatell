<?php

namespace App\Data\AiAssistant;

use App\Data\Shared\BadgeData;
use App\Models\AiThread;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use Spatie\LaravelData\Data;

/**
 * One thread in the AI chats inbox. A chat API thread shows its channel with the place ("Chat API · WhatsApp") and the
 * name n8n sent. `preview` is the handover summary, else the guest's last message (`last_guest_message`,
 * selected by `AiThreadList`). No other chat text. See docs/35-ai-assistant/03-places-and-routes.md#ai-chats-inbox.
 */
class AiThreadRowData extends Data
{
    public const array RELATIONS = ['reservationUnit:id,reservation_id', 'reservationUnit.guests.guest:id,full_name', 'reservationUnit.nights.room:id,number'];

    public function __construct(
        public string $id,
        public string $place,
        public string $placeLabel,
        public BadgeData $status,
        public ?string $guestName,
        public ?string $roomNumber,
        public ?int $reservationId,
        public ?string $preview,
        public bool $hasSummary,
        public int $turns,
        public string $lastMessageAt,
        public ?string $escalatedAt,
    ) {}

    public static function fromModel(AiThread $thread): self
    {
        $unit = $thread->reservationUnit;
        $primaryGuest = $unit?->guests->first(fn (UnitGuest $unitGuest): bool => $unitGuest->is_primary) ?? $unit?->guests->first();
        $lastNight = $unit?->nights->sortBy('date')->last(fn (UnitNight $night): bool => $night->room !== null);
        $lastGuestMessage = $thread->getAttribute('last_guest_message');

        return new self(
            id: $thread->id,
            place: $thread->place->value,
            placeLabel: $thread->channel === null ? $thread->place->label() : $thread->place->label().' · '.$thread->channel->label(),
            status: BadgeData::fromAiThreadStatus($thread->status),
            guestName: $primaryGuest?->guest->full_name ?? $thread->guest_name,
            roomNumber: $lastNight?->room?->number,
            reservationId: $unit?->reservation_id,
            preview: $thread->handover_summary ?? (is_string($lastGuestMessage) ? $lastGuestMessage : null),
            hasSummary: $thread->handover_summary !== null,
            turns: (int) $thread->getAttribute('turns_count'),
            lastMessageAt: $thread->last_message_at->toIso8601String(),
            escalatedAt: $thread->escalated_at?->toIso8601String(),
        );
    }
}
