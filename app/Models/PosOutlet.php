<?php

namespace App\Models;

use App\Enums\PosOperationMode;
use App\Enums\PosRoundingMode;
use App\Enums\PosRoundingPolicy;
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
 * @property int|null $default_charge_item_id Required for an outlet the external POS API uses. Optional when using built-in POS.
 * @property bool $active
 * @property bool $pos_enabled
 * @property int|null $service_charge_transaction_code_id
 * @property int|null $packaging_transaction_code_id
 * @property int|null $rounding_transaction_code_id
 * @property int|null $cash_variance_transaction_code_id
 * @property int|null $safe_clearing_transaction_code_id The counter code of float and safe drops between shifts.
 * @property PosOperationMode $operation_mode
 * @property string $close_sales_time
 * @property string|null $sst_registration_number
 * @property PosRoundingMode $rounding_mode
 * @property PosRoundingPolicy $rounding_policy
 * @property string|null $room_charge_limit
 * @property bool $require_room_charge_signature
 * @property bool $require_meal_redemption_confirmation
 * @property bool $require_reprint_pin
 * @property bool $require_dual_control
 * @property bool $print_receipt_after_payment
 * @property bool $print_drawer_slip
 * @property bool $require_clock_in
 * @property bool $dynamic_qr_single_use
 * @property int $dynamic_qr_ttl_minutes
 * @property bool $allow_anonymous_qr
 * @property bool $allow_qr_customer_remark
 * @property bool $qr_ignore_stock
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name', 'code', 'default_charge_item_id', 'active', 'pos_enabled',
    'service_charge_transaction_code_id', 'packaging_transaction_code_id', 'rounding_transaction_code_id', 'cash_variance_transaction_code_id', 'safe_clearing_transaction_code_id',
    'operation_mode', 'close_sales_time', 'sst_registration_number', 'rounding_mode', 'rounding_policy', 'room_charge_limit',
    'require_room_charge_signature', 'require_meal_redemption_confirmation', 'require_reprint_pin', 'require_dual_control',
    'print_receipt_after_payment', 'print_drawer_slip', 'require_clock_in', 'dynamic_qr_single_use', 'dynamic_qr_ttl_minutes',
    'allow_anonymous_qr', 'allow_qr_customer_remark', 'qr_ignore_stock',
])]
class PosOutlet extends Model
{
    /** @use HasFactory<PosOutletFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'pos_enabled' => 'boolean',
            'operation_mode' => PosOperationMode::class,
            'rounding_mode' => PosRoundingMode::class,
            'rounding_policy' => PosRoundingPolicy::class,
            'room_charge_limit' => 'decimal:2',
            'require_room_charge_signature' => 'boolean',
            'require_meal_redemption_confirmation' => 'boolean',
            'require_reprint_pin' => 'boolean',
            'require_dual_control' => 'boolean',
            'print_receipt_after_payment' => 'boolean',
            'print_drawer_slip' => 'boolean',
            'require_clock_in' => 'boolean',
            'dynamic_qr_single_use' => 'boolean',
            'allow_anonymous_qr' => 'boolean',
            'allow_qr_customer_remark' => 'boolean',
            'qr_ignore_stock' => 'boolean',
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
     * @return HasMany<PosFloor, $this>
     */
    public function floors(): HasMany
    {
        return $this->hasMany(PosFloor::class, 'pos_outlet_id');
    }

    /**
     * @return HasMany<PosDiningOption, $this>
     */
    public function diningOptions(): HasMany
    {
        return $this->hasMany(PosDiningOption::class);
    }

    /**
     * @return HasMany<PosOutletPaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PosOutletPaymentMethod::class);
    }

    /**
     * @return HasMany<PosOutletItem, $this>
     */
    public function outletItems(): HasMany
    {
        return $this->hasMany(PosOutletItem::class);
    }

    /**
     * @param  Builder<PosOutlet>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
