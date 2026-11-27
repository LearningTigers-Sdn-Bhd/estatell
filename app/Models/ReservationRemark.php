<?php

namespace App\Models;

use App\Enums\RemarkKind;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationRemarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed remark of a booking. See docs/12-reservations/16-remarks.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_id
 * @property RemarkKind $kind
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'body'])]
class ReservationRemark extends Model
{
    /** @use HasFactory<ReservationRemarkFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => RemarkKind::class,
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
