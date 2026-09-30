<?php

namespace App\Models;

use Database\Factories\PackagePriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The price of the whole stay of a package for one room type, for guests who arrive from `arrival_from` to `arrival_to`.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $room_type_id
 * @property Carbon $arrival_from
 * @property Carbon $arrival_to
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['room_type_id', 'arrival_from', 'arrival_to', 'amount'])]
class PackagePrice extends Model
{
    /** @use HasFactory<PackagePriceFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'arrival_from' => 'date',
            'arrival_to' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
