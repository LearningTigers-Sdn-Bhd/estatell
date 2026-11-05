<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share of one `payments` row in a Curlec refund. One refund for a group booking costs one Curlec fee and has one part
 * for each unit. See docs/29-booking-engine/04-payment.md#data-model.
 *
 * @property int $id
 * @property int $gateway_refund_id
 * @property int $payment_id
 * @property string $amount
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['gateway_refund_id', 'payment_id', 'amount'])]
class GatewayRefundPart extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<GatewayRefund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(GatewayRefund::class, 'gateway_refund_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
