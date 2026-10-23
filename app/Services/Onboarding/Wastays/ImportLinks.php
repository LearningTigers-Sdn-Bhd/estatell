<?php

namespace App\Services\Onboarding\Wastays;

use App\Models\ImportLink;
use App\Models\Property;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

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

    /**
     * The linked estatell row, or null when the wastays row has no link yet.
     */
    public function target(Property $property, string $sourceTable, string|int $sourceId): ?Model
    {
        $link = ImportLink::query()->forSource($property->id, $sourceTable, (string) $sourceId)->first();

        if ($link === null) {
            return null;
        }

        $class = Relation::getMorphedModel($link->target_type) ?? $link->target_type;

        return $class::query()->find($link->target_id);
    }
}
