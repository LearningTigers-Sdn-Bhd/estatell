<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\SetPartnerCanCreateProperties;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePartnerCanCreatePropertiesRequest;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PartnerCanCreatePropertiesController extends Controller
{
    /**
     * Allow or stop an Active partner from creating properties.
     */
    public function update(UpdatePartnerCanCreatePropertiesRequest $request, Partner $partner, SetPartnerCanCreateProperties $setPartnerCanCreateProperties): RedirectResponse
    {
        $allowed = $request->boolean('allowed');

        Inertia::flash('toast', $setPartnerCanCreateProperties($request->user(), $partner, $allowed)
            ? ['type' => 'success', 'message' => $allowed ? __(':name can create properties.', ['name' => $partner->name]) : __(':name can no longer create properties.', ['name' => $partner->name])]
            : ['type' => 'error', 'message' => __('Only an Active partner can be changed.')]);

        return back(fallback: route('admin.partners.index'));
    }
}
