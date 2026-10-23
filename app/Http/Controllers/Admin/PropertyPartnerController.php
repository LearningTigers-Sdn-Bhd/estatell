<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\MovePropertyToPartner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePropertyPartnerRequest;
use App\Models\Partner;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PropertyPartnerController extends Controller
{
    /**
     * Move a direct property to an Active partner, or a partner's property to direct.
     */
    public function update(UpdatePropertyPartnerRequest $request, Property $property, MovePropertyToPartner $movePropertyToPartner): RedirectResponse
    {
        $partnerId = $request->integer('partner_id') ?: null;
        $partner = $partnerId === null ? null : Partner::query()->findOrFail($partnerId);

        $moved = $movePropertyToPartner($request->user(), $property, $partner);

        Inertia::flash('toast', $moved
            ? ['type' => 'success', 'message' => $partner === null
                ? __('Property moved to direct.')
                : __('Property moved to :name.', ['name' => $partner->name])]
            : ['type' => 'error', 'message' => __('A property can move between direct and an Active partner only.')]);

        return $moved
            ? to_route('admin.properties.show', $property->refresh()->code)
            : back(fallback: route('admin.properties.show', $property));
    }
}
