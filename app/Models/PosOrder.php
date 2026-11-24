<?php

namespace App\Models;

use App\Enums\PosOrderSource;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use Carbon\CarbonImmutable;
use Database\Factories\PosOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One guest check, a sale or a refund. `business_date` stays null while it is open and is stamped when it settles or is voided, so an order opened before the Night Audit settles cleanly on the new date.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int|null $pos_shift_id
 * @property CarbonImmutable|null $business_date
 * @property bool $synced_late
 * @property string $client_uuid
 * @property string|null $order_number
 * @property PosOrderType $order_type
 * @property int|null $refund_for_order_id
 * @property int|null $parent_order_id
 * @property int $pos_dining_option_id
 * @property int|null $reservation_unit_id
 * @property int $guest_count
 * @property PosOrderSource $source
 * @property PosOrderStatus $status
 * @property int|null $opened_by_user_id
 * @property int|null $closed_by_user_id
 * @property string $subtotal
 * @property string $discount_total
 * @property string $service_charge_total
 * @property string $tax_total
 * @property string $rounding_adjustment
 * @property string $grand_total
 * @property bool $settled_on_folio
 * @property string $folio_posted_amount
 * @property string|null $order_note
 * @property int $prebill_print_count
 * @property string|null $receipt_claim_token
 * @property int|null $e_invoice_submission_id
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $void_reason_id
 * @property string|null $void_reason_text
 * @property int|null $voided_by_user_id
 * @property int|null $void_approved_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['guest_count', 'order_note'])]
class PosOrder extends Model
{
    /** @use HasFactory<PosOrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'synced_late' => 'boolean',
            'order_type' => PosOrderType::class,
            'source' => PosOrderSource::class,
            'status' => PosOrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'service_charge_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'rounding_adjustment' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'settled_on_folio' => 'boolean',
            'folio_posted_amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'settled_at' => 'datetime',
            'voided_at' => 'datetime',
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
     * @return BelongsTo<PosShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    /**
     * @return BelongsTo<PosDiningOption, $this>
     */
    public function diningOption(): BelongsTo
    {
        return $this->belongsTo(PosDiningOption::class, 'pos_dining_option_id');
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class, 'reservation_unit_id');
    }

    /**
     * @return BelongsTo<PosOrder, $this>
     */
    public function refundFor(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'refund_for_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return HasMany<PosOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PosOrderLine::class, 'pos_order_id');
    }

    /**
     * @return HasMany<PosOrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PosOrderPayment::class, 'pos_order_id');
    }

    /**
     * @return HasMany<PosOrderDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(PosOrderDiscount::class, 'pos_order_id');
    }

    /**
     * @return HasMany<PosOrderFolioLink, $this>
     */
    public function folioLinks(): HasMany
    {
        return $this->hasMany(PosOrderFolioLink::class, 'pos_order_id');
    }
}
