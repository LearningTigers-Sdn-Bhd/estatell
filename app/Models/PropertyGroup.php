<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Properties run by one business (Tanini: Sepilok and Kinabatangan). Staff can be given access to the other properties
 * of their home property's group. Companies stay per property. Platform admins create groups.
 * See docs/03-staff/04-property-groups.md.
 *
 * @property int $id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
class PropertyGroup extends Model
{
    /** @use HasFactory<PropertyGroupFactory> */
    use HasFactory;

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
