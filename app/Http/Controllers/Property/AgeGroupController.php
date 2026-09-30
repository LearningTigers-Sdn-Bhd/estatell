<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\SaveAgeGroups;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\SaveAgeGroupsRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class AgeGroupController extends Controller
{
    public function update(SaveAgeGroupsRequest $request, Property $property, SaveAgeGroups $saveAgeGroups): RedirectResponse
    {
        if (! $saveAgeGroups($property, $request->user(), $request->groups())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The age groups changed. Reload the page and try again.')]);

            return to_route('settings.age-groups', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Age groups saved.')]);

        return to_route('settings.age-groups', $property);
    }
}
