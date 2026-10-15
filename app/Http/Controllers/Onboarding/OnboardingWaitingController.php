<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Staff\PropertyOwners;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingWaitingController extends Controller
{
    /**
     * Tells staff, other than the owner, that the property is still being set up and who to ask.
     */
    public function __invoke(Property $property, PropertyOwners $propertyOwners): Response
    {
        return Inertia::render('onboarding/waiting', [
            'ownerName' => $propertyOwners->firstFor($property)?->name,
        ]);
    }
}
