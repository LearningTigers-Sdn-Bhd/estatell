<?php

namespace App\Models;

use App\Enums\AiChannel;
use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AiThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat with the AI assistant. Its messages live in the SDK's conversation `agent_conversation_id`, which the server
 * sets when the thread starts and never takes from the browser. See docs/35-ai-assistant/04-usage-and-cost.md.
 *
 * @property string $id
 * @property int $property_id
 * @property AiPlace $place
 * @property AiThreadStatus $status
 * @property string $agent_conversation_id
 * @property int|null $reservation_unit_id The stay of a Concierge stay thread. Set from the stay link, never by the model.
 * @property int|null $user_id The staff member of a Staff thread.
 * @property int|null $ai_connection_id The chat API connection the latest message came through.
 * @property string|null $external_ref_hash HMAC of the property, channel and the channel's chat id. Never the chat id.
 * @property AiChannel|null $channel
 * @property string|null $guest_name The name n8n sent. Cleared by the 90-day text purge.
 * @property string|null $language
 * @property CarbonImmutable|null $escalated_at
 * @property string|null $handover_summary The model's short summary of the last handover. Cleared by the 90-day text purge.
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property CarbonImmutable $last_message_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'place', 'status', 'agent_conversation_id', 'reservation_unit_id', 'user_id', 'ai_connection_id', 'external_ref_hash', 'channel', 'guest_name', 'language', 'escalated_at', 'handover_summary', 'resolved_at', 'resolved_by_user_id', 'last_message_at'])]
class AiThread extends Model
{
    /** @use HasFactory<AiThreadFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'place' => AiPlace::class,
            'status' => AiThreadStatus::class,
            'channel' => AiChannel::class,
            'escalated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<AiConnection, $this>
     */
    public function aiConnection(): BelongsTo
    {
        return $this->belongsTo(AiConnection::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * @return HasMany<AiTurn, $this>
     */
    public function turns(): HasMany
    {
        return $this->hasMany(AiTurn::class);
    }
}
