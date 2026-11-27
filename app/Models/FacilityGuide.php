<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FacilityGuideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guide picked for an activity session on the Activities run sheet. Never deleted; turned off instead.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $phone
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'phone', 'active'])]
class FacilityGuide extends Model
{
    /** @use HasFactory<FacilityGuideFactory> */
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
