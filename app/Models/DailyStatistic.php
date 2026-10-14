<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyStatisticFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The statistics of one closed business date, written once by the night audit. Occupancy, ADR and RevPAR count a
 * room or a bed as one sellable unit. See docs/20-night-audit/05-statistics.md.
 *
 * @property int $id
 * @property int $property_id
 * @property CarbonImmutable $business_date
 * @property int $rooms_available
 * @property int $rooms_sold
 * @property int $beds_available
 * @property int $beds_sold
 * @property string $occupancy A percent
 * @property string $adr
 * @property string $revpar
 * @property int $arrivals
 * @property int $departures
 * @property int $no_shows
 * @property int $in_house_guests
 * @property int $day_use_sold
 * @property string $day_use_revenue
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'business_date', 'rooms_available', 'rooms_sold', 'beds_available', 'beds_sold', 'occupancy', 'adr', 'revpar',
    'arrivals', 'departures', 'no_shows', 'in_house_guests', 'day_use_sold', 'day_use_revenue',
])]
class DailyStatistic extends Model
{
    /** @use HasFactory<DailyStatisticFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'occupancy' => 'decimal:2',
            'adr' => 'decimal:2',
            'revpar' => 'decimal:2',
            'day_use_revenue' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
