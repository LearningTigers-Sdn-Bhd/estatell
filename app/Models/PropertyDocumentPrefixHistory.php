<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyDocumentPrefixHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every document prefix a property has held. A prefix here is never issued to another property.
 *
 * @property int $id
 * @property int $property_id
 * @property string $prefix
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'prefix'])]
class PropertyDocumentPrefixHistory extends Model
{
    /** @use HasFactory<PropertyDocumentPrefixHistoryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
