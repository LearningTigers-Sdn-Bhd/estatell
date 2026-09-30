<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\SaveAgeGroups;
use App\Data\Property\PropertyAgeGroupData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\SaveAgeGroupsRequest;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AgeGroupController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/age-groups/index', [
            'ageGroups' => $property->ageGroups()
                ->ordered()
                ->get()
                ->map(fn (PropertyAgeGroup $ageGroup): PropertyAgeGroupData => PropertyAgeGroupData::fromModel($ageGroup)),
            'maxAge' => SaveAgeGroupsRequest::MAX_AGE,
        ]);
    }

    public function update(SaveAgeGroupsRequest $request, Property $property, SaveAgeGroups $saveAgeGroups): RedirectResponse
    {
        if (! $saveAgeGroups($property, $request->user(), $request->groups())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The age groups changed. Reload the page and try again.')]);

            return to_route('settings.age-groups.index', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Age groups saved.')]);

        return to_route('settings.age-groups.index', $property);
    }
}
