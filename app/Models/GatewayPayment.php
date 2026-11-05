<?php

namespace App\Models;

use App\Enums\GatewayPaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GatewayPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Curlec order of a hold, and whether it was paid. A hold has one, so a guest who retries cannot pay two orders. The
 * `payments` rows are written only once the money is confirmed. See docs/29-booking-engine/04-payment.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $booking_hold_id
 * @property string $gateway_account
 * @property string $order_id
 * @property string|null $gateway_payment_id
 * @property string $amount
 * @property GatewayPaymentStatus $status
 * @property string|null $return_url
 * @property array<string, mixed>|null $gateway_payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'booking_hold_id', 'gateway_account', 'order_id', 'gateway_payment_id', 'amount', 'status', 'return_url', 'gateway_payload'])]
class GatewayPayment extends Model
{
    /** @use HasFactory<GatewayPaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => GatewayPaymentStatus::class,
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
     * @return BelongsTo<BookingHold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(BookingHold::class, 'booking_hold_id');
    }
}
