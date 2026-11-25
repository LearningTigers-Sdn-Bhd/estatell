<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosVoidReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reason staff pick when they void a fired line or an order.
 *
 * @property int $id
 * @property int $property_id
 * @property string $label
 * @property bool $requires_manager_pin
 * @property bool $default_return_stock
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['label', 'requires_manager_pin', 'default_return_stock', 'sort_order', 'active'])]
class PosVoidReason extends Model
{
    /** @use HasFactory<PosVoidReasonFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_manager_pin' => 'boolean',
            'default_return_stock' => 'boolean',
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
}
