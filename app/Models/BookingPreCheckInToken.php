<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BookingPreCheckInTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $property_id
 * @property int $reservation_unit_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['property_id', 'reservation_unit_id', 'token_hash', 'expires_at'])]
class BookingPreCheckInToken extends Model
{
    /** @use HasFactory<BookingPreCheckInTokenFactory> */
    use HasFactory;

    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ReservationUnit, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class, 'reservation_unit_id');
    }
}
