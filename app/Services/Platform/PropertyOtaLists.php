<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminOtaListRowData;
use App\Models\Property;
use App\Models\PropertyOtaCredential;

/**
 * Reads one property's saved OTA logins for the admin OTA lists tab. Every lookup is scoped to the property.
 */
class PropertyOtaLists
{
    /**
     * @return list<AdminOtaListRowData>
     */
    public function forProperty(Property $property): array
    {
        return $property->otaCredentials()->with('channel')->orderBy('id')->get()
            ->map(fn (PropertyOtaCredential $credential): AdminOtaListRowData => AdminOtaListRowData::fromModel($credential))
            ->values()
            ->all();
    }

    /**
     * A credential of this property only. Another property's id is not found.
     */
    public function credentialFor(Property $property, int $credentialId): PropertyOtaCredential
    {
        return $property->otaCredentials()->with('channel')->whereKey($credentialId)->firstOrFail();
    }
}
