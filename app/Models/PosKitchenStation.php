<?php

namespace App\Models;

use App\Enums\PosStationMode;
use Carbon\CarbonImmutable;
use Database\Factories\PosKitchenStationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kitchen or bar station of an outlet. Fired lines of its categories and items are shown on its Web KDS. Its SLA
 * thresholds colour the ticket age, and `filter_dining_kinds` (comma separated) limits it to some dining kinds.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property string $name
 * @property PosStationMode $mode
 * @property bool $use_internal_name
 * @property int $warn_after_minutes
 * @property int $alert_after_minutes
 * @property string|null $filter_dining_kinds
 * @property int $sort_order
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'mode', 'use_internal_name', 'warn_after_minutes', 'alert_after_minutes', 'filter_dining_kinds', 'sort_order', 'active'])]
class PosKitchenStation extends Model
{
    /** @use HasFactory<PosKitchenStationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => PosStationMode::class,
            'use_internal_name' => 'boolean',
            'warn_after_minutes' => 'integer',
            'alert_after_minutes' => 'integer',
            'sort_order' => 'integer',
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
}
