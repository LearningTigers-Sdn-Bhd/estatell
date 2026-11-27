<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransferVehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A van or car of the property, picked for a transfer on the run sheet. Never deleted; turned off instead.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $plate
 * @property int|null $seats
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'plate', 'seats', 'active'])]
class TransferVehicle extends Model
{
    /** @use HasFactory<TransferVehicleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['seats' => 'integer', 'active' => 'boolean'];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
