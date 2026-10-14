<?php

namespace App\Http\Controllers\Partner;

use App\Actions\Platform\RegeneratePartnerSignupCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PartnerSignupLinkController extends Controller
{
    /**
     * Issue a new signup link. The old link stops working at once.
     */
    public function regenerate(Request $request, RegeneratePartnerSignupCode $regeneratePartnerSignupCode): RedirectResponse
    {
        $user = $request->user();

        Inertia::flash('toast', $regeneratePartnerSignupCode($user, $user->partner)
            ? ['type' => 'success', 'message' => __('Signup link regenerated. The old link no longer works.')]
            : ['type' => 'error', 'message' => __('The signup link could not be regenerated.')]);

        return to_route('partner.properties.index');
    }
}
