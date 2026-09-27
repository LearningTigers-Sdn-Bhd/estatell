<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\SetPropertyFeature;
use App\Enums\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePropertyFeatureRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PropertyFeatureController extends Controller
{
    /**
     * Turn a feature on or off for the property.
     */
    public function update(UpdatePropertyFeatureRequest $request, Property $property, PlatformFeature $feature, SetPropertyFeature $setPropertyFeature): RedirectResponse
    {
        $active = $request->boolean('active');

        $setPropertyFeature($request->user(), $property, $feature, $active);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $active ? __(':feature turned on.', ['feature' => $feature->label()]) : __(':feature turned off.', ['feature' => $feature->label()]),
        ]);

        return back(fallback: route('admin.properties.show', $property));
    }
}
