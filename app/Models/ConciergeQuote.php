<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConciergeQuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quote link for a future stay on the Concierge page. See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 *
 * @property int $id
 * @property int $property_id
 * @property string $token
 * @property int $room_type_id
 * @property int $rate_plan_id
 * @property CarbonImmutable $arrival_date
 * @property CarbonImmutable $departure_date
 * @property int $adults
 * @property string $quoted_total
 * @property int|null $ai_turn_id
 * @property int|null $reservation_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ConciergeQuoteChild> $children
 */
#[Fillable(['property_id', 'token', 'room_type_id', 'rate_plan_id', 'arrival_date', 'departure_date', 'adults', 'quoted_total', 'ai_turn_id', 'reservation_id', 'expires_at'])]
class ConciergeQuote extends Model
{
    /** @use HasFactory<ConciergeQuoteFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'arrival_date' => 'immutable_date',
            'departure_date' => 'immutable_date',
            'adults' => 'integer',
            'quoted_total' => 'decimal:2',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /** @return HasMany<ConciergeQuoteChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ConciergeQuoteChild::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
