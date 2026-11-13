<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyInfoTopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra topic a property tells its guests about, under its own title.
 *
 * @property int $id
 * @property int $property_id
 * @property string $title
 * @property string $body
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'title', 'body', 'position'])]
class PropertyInfoTopic extends Model
{
    /** @use HasFactory<PropertyInfoTopicFactory> */
    use HasFactory;

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
