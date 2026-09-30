<?php

namespace App\Models;

use App\Enums\EarlyDeparturePolicy;
use App\Enums\PricingBasis;
use Database\Factories\RatePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * How room types are priced and sold, such as Basic Rate or Breakfast. It holds no prices; those live in daily rates.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $description
 * @property PricingBasis $pricing_basis
 * @property string $extra_adult_amount
 * @property string $extra_child_amount
 * @property string $single_supplement
 * @property EarlyDeparturePolicy $early_departure_policy
 * @property bool $needs_child_price_review
 * @property int $position
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'description', 'pricing_basis', 'extra_adult_amount', 'extra_child_amount', 'single_supplement',
    'early_departure_policy', 'needs_child_price_review', 'position', 'archived_at',
])]
class RatePlan extends Model
{
    /** @use HasFactory<RatePlanFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'pricing_basis' => PricingBasis::class,
            'early_departure_policy' => EarlyDeparturePolicy::class,
            'extra_adult_amount' => 'decimal:2',
            'extra_child_amount' => 'decimal:2',
            'single_supplement' => 'decimal:2',
            'needs_child_price_review' => 'boolean',
            'archived_at' => 'datetime',
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
     * @return BelongsToMany<RoomType, $this>
     */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class, 'rate_plan_room_types')->withTimestamps();
    }

    /**
     * @return HasMany<RatePlanChildPrice, $this>
     */
    public function childPrices(): HasMany
    {
        return $this->hasMany(RatePlanChildPrice::class);
    }

    /**
     * @return HasMany<RatePlanStayDiscount, $this>
     */
    public function stayDiscounts(): HasMany
    {
        return $this->hasMany(RatePlanStayDiscount::class);
    }

    /**
     * @return HasMany<Rate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class);
    }

    /**
     * @param  Builder<RatePlan>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<RatePlan>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
