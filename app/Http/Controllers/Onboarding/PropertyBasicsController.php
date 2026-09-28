<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SavePropertyBasics;
use App\Data\Property\PropertyBasicsData;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePropertyBasicsRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PropertyBasicsController extends Controller
{
    public function edit(Property $property): Response
    {
        return Inertia::render('onboarding/basics', [
            'basics' => PropertyBasicsData::fromModel($property),
            'propertyTypes' => PropertyType::options(),
        ]);
    }

    public function update(SavePropertyBasicsRequest $request, Property $property, SavePropertyBasics $savePropertyBasics): RedirectResponse
    {
        $savePropertyBasics($property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property basics saved.')]);

        return to_route('settings.edit', ['property' => $property->code]);
    }
}
