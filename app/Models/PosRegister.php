<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosRegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier terminal inside an outlet. It trades through one shift at a time.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property string $name
 * @property bool $active
 * @property int|null $active_pos_order_id The check the customer-facing display shows, or the one just settled.
 * @property CarbonImmutable|null $active_order_updated_at
 * @property bool $cfd_checkout_active Whether the cashier has the checkout sheet open.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'active'])]
class PosRegister extends Model
{
    /** @use HasFactory<PosRegisterFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'active_order_updated_at' => 'datetime',
            'cfd_checkout_active' => 'boolean',
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
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @return HasMany<PosShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class, 'pos_register_id');
    }
}
