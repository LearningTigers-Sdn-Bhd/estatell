<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ImportLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that one row of an imported source (a wastays table row) is already written in estatell, so a stopped run resumes without posting twice.
 *
 * @property int $id
 * @property int $property_id
 * @property string $source_table
 * @property string $source_id
 * @property string $target_type
 * @property int $target_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['property_id', 'source_table', 'source_id', 'target_type', 'target_id'])]
class ImportLink extends Model
{
    /** @use HasFactory<ImportLinkFactory> */
    use HasFactory;

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @param  Builder<ImportLink>  $query */
    public function scopeForSource(Builder $query, int $propertyId, string $sourceTable, string $sourceId): void
    {
        $query->where('property_id', $propertyId)
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId);
    }
}
