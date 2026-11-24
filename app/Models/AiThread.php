<?php

namespace App\Models;

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
 * sets when the thread starts and never takes from the browser. See docs/34-ai-assistant/04-usage-and-cost.md.
 *
 * @property string $id
 * @property int $property_id
 * @property AiPlace $place
 * @property AiThreadStatus $status
 * @property string $agent_conversation_id
 * @property int|null $reservation_unit_id The stay of a Concierge stay thread. Set from the stay link, never by the model.
 * @property int|null $user_id The staff member of a Staff thread.
 * @property string|null $language
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable $last_message_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'place', 'status', 'agent_conversation_id', 'reservation_unit_id', 'user_id', 'language', 'escalated_at', 'last_message_at'])]
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
            'escalated_at' => 'immutable_datetime',
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
     * @return HasMany<AiTurn, $this>
     */
    public function turns(): HasMany
    {
        return $this->hasMany(AiTurn::class);
    }
}
