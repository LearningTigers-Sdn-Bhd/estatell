<?php

namespace App\Models;

use App\Enums\TransferDirection;
use Carbon\CarbonImmutable;
use Database\Factories\TransferRouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-way road route of the property, such as the airport to the hotel, with its shared and private prices as charge
 * items. Never deleted; turned off instead. See docs/34-transfers/01-land-transfers.md.
 *
 * @property int $id
 * @property int $property_id
 * @property TransferDirection $direction
 * @property string $name
 * @property string $place Where the guest is picked up (In) or dropped (Out).
 * @property int|null $minutes
 * @property int|null $shared_charge_item_id
 * @property int|null $private_charge_item_id
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['direction', 'name', 'place', 'minutes', 'shared_charge_item_id', 'private_charge_item_id', 'active'])]
class TransferRoute extends Model
{
    /** @use HasFactory<TransferRouteFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['direction' => TransferDirection::class, 'minutes' => 'integer', 'active' => 'boolean'];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<ChargeItem, $this> */
    public function sharedChargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class, 'shared_charge_item_id');
    }

    /** @return BelongsTo<ChargeItem, $this> */
    public function privateChargeItem(): BelongsTo
    {
        return $this->belongsTo(ChargeItem::class, 'private_charge_item_id');
    }
}
