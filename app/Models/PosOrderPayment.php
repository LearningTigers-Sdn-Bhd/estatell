<?php

namespace App\Models;

use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosReconciliationStatus;
use App\Enums\PosTenderType;
use Carbon\CarbonImmutable;
use Database\Factories\PosOrderPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tender of an order: money in, or money paid out on a refund. A refund points at the exact payment it returns money to. Rows are never edited; a swapped tender is marked Replaced.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_order_id
 * @property int $pos_shift_id
 * @property string $idempotency_key
 * @property int|null $refund_of_payment_id
 * @property int|null $payment_method_id
 * @property PosPaymentDirection $direction
 * @property PosTenderType $tender_type
 * @property PosReconciliationStatus $reconciliation_status
 * @property string $amount
 * @property string|null $tendered_amount
 * @property string|null $change_amount
 * @property string $rounding_adjustment
 * @property int|null $reservation_unit_id
 * @property int|null $folio_id
 * @property string|null $reference
 * @property PosPaymentStatus $status
 * @property int|null $replaced_by_payment_id
 * @property int $acted_by_user_id
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable $paid_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PosOrderPayment extends Model
{
    /** @use HasFactory<PosOrderPaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => PosPaymentDirection::class,
            'tender_type' => PosTenderType::class,
            'reconciliation_status' => PosReconciliationStatus::class,
            'amount' => 'decimal:2',
            'tendered_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'rounding_adjustment' => 'decimal:2',
            'status' => PosPaymentStatus::class,
            'paid_at' => 'datetime',
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
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    /**
     * @return BelongsTo<PosShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * @return BelongsTo<PosOrderPayment, $this>
     */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(PosOrderPayment::class, 'refund_of_payment_id');
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class, 'reservation_unit_id');
    }

    /**
     * @return BelongsTo<Folio, $this>
     */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_id');
    }
}
