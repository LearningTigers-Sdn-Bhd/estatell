<?php

namespace App\Models;

use App\Enums\AiOutcome;
use App\Enums\AiPlace;
use Carbon\CarbonImmutable;
use Database\Factories\AiTurnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One reply of the AI assistant: its tokens, its cost and how it ended. It keeps no message text.
 * See docs/35-ai-assistant/04-usage-and-cost.md#data-model.
 *
 * @property int $id
 * @property int $property_id
 * @property string $ai_thread_id
 * @property AiPlace $place
 * @property string|null $language
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cached_input_tokens
 * @property int $cost_micros Millionths of a US dollar.
 * @property AiOutcome $outcome
 * @property int $duration_ms
 * @property int|null $reservation_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['property_id', 'ai_thread_id', 'place', 'language', 'model', 'input_tokens', 'output_tokens', 'cached_input_tokens', 'cost_micros', 'outcome', 'duration_ms', 'reservation_id', 'created_at'])]
class AiTurn extends Model
{
    /** @use HasFactory<AiTurnFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['place' => AiPlace::class, 'outcome' => AiOutcome::class, 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<AiThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'ai_thread_id');
    }

    /**
     * @return HasMany<AiTurnTool, $this>
     */
    public function tools(): HasMany
    {
        return $this->hasMany(AiTurnTool::class);
    }
}
