<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RateOccupancyPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit price for a number of adults on one daily rate.
 *
 * @property int $id
 * @property int $rate_id
 * @property int $adults
 * @property string $amount
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['adults', 'amount'])]
class RateOccupancyPrice extends Model
{
    /** @use HasFactory<RateOccupancyPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'adults' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Rate, $this>
     */
    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }
}
