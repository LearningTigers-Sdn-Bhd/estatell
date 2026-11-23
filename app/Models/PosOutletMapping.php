<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links one external store of a POS connection to an outlet, with the payment type that means "Room Charge".
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_connection_id
 * @property int $pos_outlet_id
 * @property string $external_store_id
 * @property string $external_payment_type_id
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['pos_outlet_id', 'external_store_id', 'external_payment_type_id', 'active'])]
class PosOutletMapping extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<PosConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PosConnection::class, 'pos_connection_id');
    }

    /**
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @param  Builder<PosOutletMapping>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
