<?php

namespace App\Actions\Onboarding\Wastays;

use App\Models\ImportLink;
use App\Models\Property;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that a wastays row is written in estatell as the given model. Recording the same row again keeps the first link.
 */
class RecordImportLink
{
    public function __invoke(Property $property, string $sourceTable, string|int $sourceId, Model $target): ImportLink
    {
        return ImportLink::query()->firstOrCreate(
            [
                'property_id' => $property->id,
                'source_table' => $sourceTable,
                'source_id' => (string) $sourceId,
            ],
            [
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
            ],
        );
    }
}
