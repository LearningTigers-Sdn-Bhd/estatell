<?php

namespace App\Models;

use App\Enums\TaxRateType;
use Database\Factories\PosOrderLineTaxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax on an order line: the rate at order time, the base it was worked out on and the amount.
 *
 * @property int $id
 * @property int $pos_order_line_id
 * @property int $tax_id
 * @property int $transaction_code_id
 * @property string $tax_code
 * @property string $tax_name
 * @property string|null $lhdn_tax_type
 * @property TaxRateType $rate_type
 * @property string $tax_rate
 * @property bool $is_inclusive
 * @property string $taxable_base
 * @property string $tax_amount
 */
class PosOrderLineTax extends Model
{
    /** @use HasFactory<PosOrderLineTaxFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_type' => TaxRateType::class,
            'tax_rate' => 'decimal:2',
            'is_inclusive' => 'boolean',
            'taxable_base' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PosOrderLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PosOrderLine::class, 'pos_order_line_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }
}
