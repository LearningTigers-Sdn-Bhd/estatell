<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosOrderFolioLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The root charge line a room charge posted to a guest folio, so a refund can reverse exactly that line. Child tax lines are not linked.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_order_id
 * @property int $pos_order_payment_id
 * @property int $folio_id
 * @property int $folio_line_id
 * @property int $transaction_code_id
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $gross_amount
 * @property string $refunded_net
 * @property string $refunded_tax
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PosOrderFolioLink extends Model
{
    /** @use HasFactory<PosOrderFolioLinkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'refunded_net' => 'decimal:2',
            'refunded_tax' => 'decimal:2',
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
     * @return BelongsTo<PosOrderPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PosOrderPayment::class, 'pos_order_payment_id');
    }

    /**
     * @return BelongsTo<Folio, $this>
     */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'folio_id');
    }

    /**
     * @return BelongsTo<FolioLine, $this>
     */
    public function folioLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class, 'folio_line_id');
    }
}
