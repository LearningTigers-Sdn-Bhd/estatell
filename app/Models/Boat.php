<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BoatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A boat of the property, picked for a departure on a date (`BoatDepartureRun`). Never deleted; turned off instead.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $registration
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'registration', 'active'])]
class Boat extends Model
{
    /** @use HasFactory<BoatFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
