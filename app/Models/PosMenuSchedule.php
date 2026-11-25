<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosMenuScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Days and hours an outlet sells certain categories and items. Items named by no active schedule are always on sale.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int|null $meal_period_id
 * @property string $name
 * @property int $days_of_week
 * @property string $start_time
 * @property string $end_time
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PosMenuSchedule extends Model
{
    /** @use HasFactory<PosMenuScheduleFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_of_week' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'active' => 'boolean',
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
     * @return HasMany<PosMenuScheduleItem, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PosMenuScheduleItem::class, 'pos_menu_schedule_id');
    }
}
