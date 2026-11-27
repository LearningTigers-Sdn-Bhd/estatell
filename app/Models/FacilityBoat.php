<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FacilityBoatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A boat (or vehicle) of the activities, such as a river safari boat, picked for a session on the Activities run sheet.
 * Not the island transfer boats of the Boats module. Never deleted; turned off instead.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property int|null $seats
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'seats', 'active'])]
class FacilityBoat extends Model
{
    /** @use HasFactory<FacilityBoatFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'seats' => 'integer'];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
