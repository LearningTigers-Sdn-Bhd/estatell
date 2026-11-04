<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KioskWalkInHoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $property_id
 * @property int $reservation_unit_id
 * @property int|null $api_token_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $released_at
 */
#[Fillable(['property_id', 'reservation_unit_id', 'api_token_id', 'expires_at', 'released_at'])]
class KioskWalkInHold extends Model
{
    /** @use HasFactory<KioskWalkInHoldFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ReservationUnit, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class, 'reservation_unit_id');
    }
}
