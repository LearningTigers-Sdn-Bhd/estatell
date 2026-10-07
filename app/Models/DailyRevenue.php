<?php

namespace App\Models;

use Database\Factories\DailyRevenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The folio lines of one closed business date for one transaction code, summed by the night audit. A copy of
 * `DailyRevenueQuery` for that date. See docs/20-night-audit/05-statistics.md.
 *
 * @property int $id
 * @property int $property_id
 * @property Carbon $business_date
 * @property int $transaction_code_id
 * @property string $amount
 * @property int $line_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_date', 'transaction_code_id', 'amount', 'line_count'])]
class DailyRevenue extends Model
{
    /** @use HasFactory<DailyRevenueFactory> */
    use HasFactory;

    protected $table = 'daily_revenue';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'amount' => 'decimal:2',
            'line_count' => 'integer',
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
     * @return BelongsTo<TransactionCode, $this>
     */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class);
    }
}
