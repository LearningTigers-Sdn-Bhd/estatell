<?php

namespace App\Http\Controllers;

use App\Enums\PropertyStatus;
use App\Models\PropertyGroup;
use App\Services\Staff\GroupProperties;
use App\Services\Staff\PropertyOwners;
use App\Services\Staff\StaffProperties;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeRedirectController extends Controller
{
    /**
     * Send a signed-in user to their home: the admin dashboard, the partner portal, the company portal or their property's dashboard.
     * Fortify's `home` points here, so every auth flow lands in the right place.
     */
    public function __invoke(Request $request, PropertyOwners $propertyOwners, StaffProperties $staffProperties, GroupProperties $groupProperties): RedirectResponse
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

        $group = $user->last_property_group_id === null ? null : PropertyGroup::query()->find($user->last_property_group_id);

        if ($group !== null && $groupProperties->propertiesFor($user, $group)->count() >= 2) {
            return to_route('group.dashboard', ['propertyGroup' => $group->id]);
        }

        $property = $staffProperties->defaultFor($user);
        abort_if($property === null, 403, __('Your access to every property is turned off.'));

        if ($property->status !== PropertyStatus::Live && $propertyOwners->firstFor($property)?->is($user)) {
            return to_route('onboarding.basics.edit', ['property' => $property->code]);
        }

        return to_route('dashboard', ['property' => $property->code]);
    }
}
