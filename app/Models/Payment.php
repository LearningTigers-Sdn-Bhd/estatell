<?php

namespace App\Models;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received on a folio, or given back (a refund, with `refund_of_id`). `amount` is always positive: for a
 * payment it is the total collected (the amount paid on the bill plus any surcharge and its tax), for a refund the
 * amount given back. `folio_line_id` is its ledger line. A database trigger rejects every UPDATE and DELETE.
 * See docs/14-payments/01-payments-and-refunds.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $folio_id
 * @property int $payment_method_id
 * @property string $amount
 * @property PaymentPurpose $purpose
 * @property PaymentStatus $status
 * @property string|null $reference
 * @property int|null $refund_of_id
 * @property string|null $refund_reason
 * @property bool $voided
 * @property string|null $receipt_number
 * @property int $folio_line_id
 * @property int|null $surcharge_line_id
 * @property int $received_by
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'folio_id', 'payment_method_id', 'amount', 'purpose', 'status', 'reference', 'refund_of_id', 'refund_reason', 'voided', 'receipt_number',
    'folio_line_id', 'surcharge_line_id', 'received_by', 'received_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'voided' => 'boolean',
            'received_at' => 'datetime',
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
     * @return BelongsTo<Folio, $this>
     */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'refund_of_id');
    }

    /**
     * @return BelongsTo<FolioLine, $this>
     */
    public function folioLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class);
    }

    /**
     * @return BelongsTo<FolioLine, $this>
     */
    public function surchargeLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class, 'surcharge_line_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
