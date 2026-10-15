<?php

namespace App\Models;

use App\Enums\GoLiveDecision;
use Carbon\CarbonImmutable;
use Database\Factories\GoLiveReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One go-live request. Each submit is a new row; a row without a decision is pending (at most one per property).
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $submitted_by
 * @property CarbonImmutable $submitted_at
 * @property GoLiveDecision|null $decision
 * @property string|null $note
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $opening_date
 */
#[Fillable(['property_id', 'submitted_by', 'submitted_at', 'decision', 'note', 'decided_by', 'decided_at', 'opening_date'])]
class GoLiveReview extends Model
{
    /** @use HasFactory<GoLiveReviewFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'decision' => GoLiveDecision::class,
            'decided_at' => 'immutable_datetime',
            'opening_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<GoLiveReviewFlag, $this> */
    public function flags(): HasMany
    {
        return $this->hasMany(GoLiveReviewFlag::class);
    }

    /**
     * @param  Builder<GoLiveReview>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('decision');
    }
}
