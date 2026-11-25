<?php

namespace App\Models;

use App\Enums\PosDiningKind;
use Carbon\CarbonImmutable;
use Database\Factories\PosDiningOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an order is served (dine-in, takeaway, room service) and which service charge and packaging fee it carries.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property string $name
 * @property PosDiningKind $kind
 * @property bool $apply_service_charge
 * @property string $service_charge_rate
 * @property bool $service_charge_taxable
 * @property string $packaging_fee
 * @property bool $is_default
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'kind', 'apply_service_charge', 'service_charge_rate', 'service_charge_taxable', 'packaging_fee', 'is_default', 'active'])]
class PosDiningOption extends Model
{
    /** @use HasFactory<PosDiningOptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PosDiningKind::class,
            'apply_service_charge' => 'boolean',
            'service_charge_rate' => 'decimal:2',
            'service_charge_taxable' => 'boolean',
            'packaging_fee' => 'decimal:2',
            'is_default' => 'boolean',
            'active' => 'boolean',
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
}
