<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosTimeClockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of a staff member's work at an outlet, from clock in to clock out. Open while `clocked_out_at` is empty.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int $user_id
 * @property CarbonImmutable $clocked_in_at
 * @property CarbonImmutable|null $clocked_out_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PosTimeClock extends Model
{
    /** @use HasFactory<PosTimeClockFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clocked_in_at' => 'datetime',
            'clocked_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
