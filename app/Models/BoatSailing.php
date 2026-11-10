<?php

namespace App\Models;

use App\Enums\BoatDirection;
use App\Enums\BoatJobType;
use App\Enums\SailingKind;
use Carbon\CarbonImmutable;
use Database\Factories\BoatSailingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What runs on one date: one boat, its crew and its time. A departure sailing (`boat_departure_id` set) is the departure
 * on that date; null times use the timetable. The main sailing is the departure's sailing on the date with the lowest id.
 * See docs/24-boats/04-sailings.md.
 *
 * @property int $id
 * @property int $property_id
 * @property CarbonImmutable $date
 * @property SailingKind $kind
 * @property BoatDirection|null $direction
 * @property int|null $boat_departure_id
 * @property string|null $leaves_at Time of day, HH:MM:SS.
 * @property string|null $arrives_at Time of day, HH:MM:SS.
 * @property string|null $pickup_point
 * @property int|null $boat_id
 * @property BoatJobType|null $job_type
 * @property string|null $note
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $done_at
 * @property int|null $done_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'date', 'kind', 'direction', 'boat_departure_id', 'leaves_at', 'arrives_at', 'pickup_point', 'boat_id', 'job_type', 'note', 'cancelled_at', 'cancelled_by', 'done_at', 'done_by'])]
class BoatSailing extends Model
{
    /** @use HasFactory<BoatSailingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'kind' => SailingKind::class,
            'direction' => BoatDirection::class,
            'job_type' => BoatJobType::class,
            'cancelled_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<BoatDeparture, $this> */
    public function boatDeparture(): BelongsTo
    {
        return $this->belongsTo(BoatDeparture::class);
    }

    /** @return BelongsTo<Boat, $this> */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }

    /**
     * The crew of this sailing: one member is in charge (`pivot->in_charge`), the rest are assistants.
     *
     * @return BelongsToMany<BoatCrewMember, $this>
     */
    public function crew(): BelongsToMany
    {
        return $this->belongsToMany(BoatCrewMember::class, 'boat_sailing_crew', 'boat_sailing_id', 'boat_crew_member_id')
            ->withPivot('in_charge')
            ->withTimestamps()
            ->orderByDesc('boat_sailing_crew.in_charge')
            ->orderBy('boat_crew_members.name');
    }

    /** @return HasMany<UnitBoatTrip, $this> The trips moved to this sailing. Trips with no sailing are not in it. */
    public function trips(): HasMany
    {
        return $this->hasMany(UnitBoatTrip::class);
    }

    /** @param Builder<BoatSailing> $query */
    public function scopeNotCancelled(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }
}
