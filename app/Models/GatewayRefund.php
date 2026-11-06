<?php

namespace App\Models;

use App\Enums\GatewayRefundStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GatewayRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One refund asked of Curlec. Its parts name the `payments` rows it gives back, and the folio lines are posted only when
 * Curlec confirms it. A late payment whose rooms are taken has no parts, because it has no `payments` row.
 * See docs/29-booking-engine/05-cancel-and-refund.md#refund-status.
 *
 * @property int $id
 * @property int $property_id
 * @property int $gateway_payment_id
 * @property string|null $gateway_refund_id
 * @property string $idempotency_key
 * @property string $amount
 * @property GatewayRefundStatus $status
 * @property int $attempts
 * @property string|null $reason
 * @property string|null $failure_reason
 * @property int|null $requested_by
 * @property array<string, mixed>|null $gateway_payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'gateway_payment_id', 'gateway_refund_id', 'idempotency_key', 'amount', 'status', 'attempts', 'reason', 'failure_reason', 'requested_by', 'gateway_payload'])]
class GatewayRefund extends Model
{
    /** @use HasFactory<GatewayRefundFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => GatewayRefundStatus::class,
            'attempts' => 'integer',
            'gateway_payload' => 'array',
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
     * @return BelongsTo<GatewayPayment, $this>
     */
    public function gatewayPayment(): BelongsTo
    {
        return $this->belongsTo(GatewayPayment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<GatewayRefundPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(GatewayRefundPart::class);
    }
}
