<?php

namespace App\Models;

use Database\Factories\RateHourPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The price of a day use block of a number of hours on one daily rate.
 *
 * @property int $id
 * @property int $rate_id
 * @property int $hours
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['hours', 'amount'])]
class RateHourPrice extends Model
{
    /** @use HasFactory<RateHourPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'integer',
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
