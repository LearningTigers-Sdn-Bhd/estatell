<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupDashboard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupDashboardController extends Controller
{
    /**
     * The group dashboard: today at every property of the group the user may open, side by side, with the totals.
     * See docs/03-staff/05-group-mode.md.
     */
    public function __invoke(Request $request, PropertyGroup $propertyGroup, GroupDashboard $dashboard): Response
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);

        return Inertia::render('group/dashboard', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'dashboard' => Inertia::defer(fn () => $dashboard->for($request->user(), $properties)),
        ]);
    }
}
