<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyWifiNetworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Wi-Fi network a property shares with guests who are in the house. The password is encrypted at rest.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $password
 * @property string|null $note
 * @property bool $active Shown to guests. A hidden network is for staff only.
 * @property bool $primary_network The main network, listed first. At most one for a property.
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'name', 'password', 'note', 'active', 'primary_network', 'position'])]
class PropertyWifiNetwork extends Model
{
    /** @use HasFactory<PropertyWifiNetworkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'encrypted', 'active' => 'boolean', 'primary_network' => 'boolean'];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
