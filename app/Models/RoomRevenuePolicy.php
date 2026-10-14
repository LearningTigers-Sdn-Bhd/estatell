<?php

namespace App\Models;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use Carbon\CarbonImmutable;
use Database\Factories\RoomRevenuePolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a property charges for a no-show, a cancellation, an early departure or a late check-out.
 *
 * @property int $id
 * @property int $property_id
 * @property SystemTransactionCode $code
 * @property PolicyChargeMode $charge_mode
 * @property string|null $amount
 * @property string|null $percent
 * @property PolicyRateBasis|null $percent_basis
 * @property PolicyScope $applies_to
 * @property int|null $free_until_days
 * @property bool $own_taxes True when the policy code keeps its own taxes instead of following the Taxes tab.
 * @property string $text_en
 * @property string $text_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'code', 'charge_mode', 'amount', 'percent', 'percent_basis', 'applies_to',
    'free_until_days', 'own_taxes', 'text_en', 'text_ms',
])]
class RoomRevenuePolicy extends Model
{
    /** @use HasFactory<RoomRevenuePolicyFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'code' => SystemTransactionCode::class,
            'charge_mode' => PolicyChargeMode::class,
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
            'percent_basis' => PolicyRateBasis::class,
            'applies_to' => PolicyScope::class,
            'free_until_days' => 'integer',
            'own_taxes' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
