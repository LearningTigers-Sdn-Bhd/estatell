<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FacilitySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fixed time of a facility. A booking copies its times and keeps no link to it.
 *
 * @property int $id
 * @property int $property_id
 * @property int $facility_id
 * @property string $starts_at HH:MM:SS
 * @property string $ends_at HH:MM:SS
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['starts_at', 'ends_at'])]
class FacilitySession extends Model
{
    /** @use HasFactory<FacilitySessionFactory> */
    use HasFactory;

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @param Builder<FacilitySession> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('starts_at')->orderBy('ends_at');
    }
}
