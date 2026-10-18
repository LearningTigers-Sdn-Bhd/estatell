<?php

namespace App\Http\Controllers;

use App\Data\Dashboard\DashboardAccessData;
use App\Data\Dashboard\DashboardAlertData;
use App\Data\Dashboard\PerformancePeriodData;
use App\Data\NightAudit\NightAuditReservationData;
use App\Enums\DashboardTab;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Dashboard\DashboardAlert;
use App\Services\Dashboard\DashboardTiles;
use App\Services\Dashboard\DashboardTodo;
use App\Services\Dashboard\PerformancePeriod;
use App\Services\Dashboard\PerformanceSummary;
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
        PerformanceSummary $performance,
    ): Response {
        $businessDate = $currentBusinessDate->get($property);
        $access = DashboardAccessData::forUser($request->user());
        $date = $businessDate === null ? null : CarbonImmutable::parse($businessDate->date->toDateString());

        $alertData = $alert->forBusinessDate($property, $businessDate);
        $tab = $access->canViewReports ? DashboardTab::fromRequest($request->query('tab')) : DashboardTab::Today;

        if ($tab === DashboardTab::Performance && $date !== null) {
            return $this->performance($request, $property, $date, $alertData, $access, $performance);
        }

        return Inertia::render('dashboard', [
            'tab' => DashboardTab::Today,
            'alert' => $alertData,
            'can' => $access,
            'youreLive' => $youreLive->forProperty($property, $request->user()),
            'recommended' => $access->canManageSettings ? $recommendedChecklist->forProperty($property) : null,
            'period' => null,
            'performance' => null,
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

    /**
     * The Performance tab: only its own props are sent. Today's tiles, To do, housekeeping and onboarding cards are null.
     */
    private function performance(Request $request, Property $property, CarbonImmutable $date, ?DashboardAlertData $alert, DashboardAccessData $access, PerformanceSummary $summary): Response
    {
        $earliest = $summary->earliestDate($property);
        $period = PerformancePeriod::fromRequest($request->query('period'), $request->query('month'), $date, $earliest);

        return Inertia::render('dashboard', [
            'tab' => DashboardTab::Performance,
            'alert' => $alert,
            'can' => $access,
            'youreLive' => null,
            'recommended' => null,
            'period' => PerformancePeriodData::fromPeriod($period, $date, $earliest),
            'performance' => Inertia::defer(fn () => $summary->forPeriod($property, $period), 'performance'),
            'tiles' => null,
            'todo' => null,
            'housekeeping' => null,
        ]);
    }

    public function reservation(Request $request, Property $property, Reservation $reservation, ReservationOverview $overview, UnitActionMenu $actionMenu, ReservationFormOptions $options): JsonResponse
    {
        return response()->json(NightAuditReservationData::forReservation($reservation, $property, $request->user(), $overview, $actionMenu, $options));
    }
}
