<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosOutletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One place that sells to guests (restaurant, bar, spa) and bills a room. Its charge item gives the transaction code,
 * GL account and taxes of every receipt. An outlet is deactivated, never deleted, once a posting or mapping uses it.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string $code Uppercase, unique per property.
 * @property int $default_charge_item_id
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'code', 'default_charge_item_id', 'active'])]
class PosOutlet extends Model
{
    /** @use HasFactory<PosOutletFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<ChargeItem, $this>
     */
    public function defaultChargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class, 'default_charge_item_id');
    }

    /**
     * @return HasMany<PosPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(PosPosting::class);
    }

    /**
     * @return HasMany<PosOutletMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(PosOutletMapping::class);
    }

    /**
     * @param  Builder<PosOutlet>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
