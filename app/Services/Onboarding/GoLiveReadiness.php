<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\GoLiveCheckData;
use App\Models\Property;

/**
 * The required onboarding steps a property must finish before it can go live.
 * The list starts empty and gains one check per wizard step (docs/17-onboarding/02-wizard-steps.md).
 */
class GoLiveReadiness
{
    /**
     * @return list<GoLiveCheckData>
     */
    public function checks(Property $property): array
    {
        return [];
    }

    public function isReady(Property $property): bool
    {
        return collect($this->checks($property))->every(fn (GoLiveCheckData $check): bool => $check->done);
    }
}
