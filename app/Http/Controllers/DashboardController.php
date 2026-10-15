<?php

namespace App\Http\Controllers;

use App\Data\Dashboard\DashboardAccessData;
use App\Data\NightAudit\NightAuditReservationData;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Dashboard\DashboardAlert;
use App\Services\Dashboard\DashboardTiles;
use App\Services\Dashboard\DashboardTodo;
use App\Services\Housekeeping\HousekeepingSummary;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Onboarding\RecommendedChecklist;
use App\Services\Onboarding\YoureLiveSummary;
use App\Services\Reservations\ReservationFormOptions;
use App\Services\Reservations\ReservationOverview;
use App\Services\Reservations\UnitActionMenu;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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
        DashboardTodo $todo,
        HousekeepingSummary $housekeeping,
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
            'todo' => $date !== null && $access->canViewReservations
                ? Inertia::defer(fn () => $todo->forDate($property, $date, $request->user()), 'todo')
                : null,
            'housekeeping' => $date !== null && $access->canViewHousekeeping
                ? Inertia::defer(fn () => $housekeeping->forDate($property, $date), 'housekeeping')
                : null,
        ]);
    }

    public function reservation(Request $request, Property $property, Reservation $reservation, ReservationOverview $overview, UnitActionMenu $actionMenu, ReservationFormOptions $options): JsonResponse
    {
        return response()->json(NightAuditReservationData::forReservation($reservation, $property, $request->user(), $overview, $actionMenu, $options));
    }
}
