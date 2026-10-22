<?php

namespace App\Services\Onboarding\Wastays;

use App\Models\ImportLink;
use App\Models\Property;

/**
 * Looks up which estatell row a wastays row was written as, so an import skips rows it already wrote.
 */
class ImportLinks
{
    public function targetId(Property $property, string $sourceTable, string|int $sourceId): ?int
    {
        return ImportLink::query()
            ->forSource($property->id, $sourceTable, (string) $sourceId)
            ->value('target_id');
    }

    public function has(Property $property, string $sourceTable, string|int $sourceId): bool
    {
        return $this->targetId($property, $sourceTable, $sourceId) !== null;
    }
}
