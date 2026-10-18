<?php

namespace App\Http\Controllers;

use App\Enums\PropertyStatus;
use App\Services\Staff\PropertyOwners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeRedirectController extends Controller
{
    /**
     * Send a signed-in user to their home: the admin dashboard, the partner portal, the company portal or their property's dashboard.
     * Fortify's `home` points here, so every auth flow lands in the right place.
     */
    public function __invoke(Request $request, PropertyOwners $propertyOwners): RedirectResponse
    {
        $user = $request->user();

        if ($user->is_admin) {
            return to_route('admin.dashboard');
        }

        if ($user->partner_id !== null) {
            return to_route('partner.properties.index');
        }

        if ($user->is_company_user) {
            return to_route('portal.index');
        }

        if ($user->property->status !== PropertyStatus::Live && $propertyOwners->firstFor($user->property)?->is($user)) {
            return to_route('onboarding.basics.edit', ['property' => $user->property->code]);
        }

        return to_route('dashboard', ['property' => $user->property->code]);
    }
}
