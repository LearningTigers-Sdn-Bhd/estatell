<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupDashboard;
use App\Services\Group\GroupForecast;
use App\Services\Property\PropertyToday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupDashboardController extends Controller
{
    /** The months of the forecast strip. */
    private const int FORECAST_MONTHS = 6;

    /**
     * The group dashboard: today at every property of the group the user may open, side by side, with the totals, the
     * night audit alerts and the next six months on the books.
     * See docs/03-staff/05-group-mode.md.
     */
    public function __invoke(Request $request, PropertyGroup $propertyGroup, GroupDashboard $dashboard, GroupForecast $forecast, PropertyToday $propertyToday): Response
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);

        return Inertia::render('group/dashboard', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'dashboard' => Inertia::defer(fn () => $dashboard->for($request->user(), $properties)),
            // The next six months, loaded on its own so the day's figures do not wait for it.
            'forecast' => Inertia::defer(fn () => $forecast->for($request->user(), $properties, $propertyToday->date($properties->firstOrFail())->startOfMonth(), self::FORECAST_MONTHS), 'forecast'),
        ]);
    }
}
