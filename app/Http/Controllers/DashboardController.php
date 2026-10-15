<?php

namespace App\Http\Controllers;

use App\Data\Dashboard\DashboardAccessData;
use App\Models\Property;
use App\Services\Dashboard\DashboardAlert;
use App\Services\Dashboard\DashboardTiles;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Onboarding\RecommendedChecklist;
use App\Services\Onboarding\YoureLiveSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The dashboard: the night audit alert and what the user may do come with the page, the tiles load after it.
     */
    public function show(
        Request $request,
        Property $property,
        CurrentBusinessDate $currentBusinessDate,
        DashboardAlert $alert,
        DashboardTiles $tiles,
        YoureLiveSummary $youreLive,
        RecommendedChecklist $recommendedChecklist,
    ): Response {
        $businessDate = $currentBusinessDate->get($property);
        $access = DashboardAccessData::forUser($request->user());
        $date = $businessDate === null ? null : CarbonImmutable::parse($businessDate->date->toDateString());

        return Inertia::render('dashboard', [
            'alert' => $alert->forBusinessDate($property, $businessDate),
            'can' => $access,
            'youreLive' => $youreLive->forProperty($property, $request->user()),
            'recommended' => $access->canManageSettings ? $recommendedChecklist->forProperty($property) : null,
            'tiles' => $date !== null && $access->hasTiles()
                ? Inertia::defer(fn () => $tiles->forDate($property, $date, $access->canViewReservations, $access->canViewReports))
                : null,
        ]);
    }
}
