<?php

namespace App\Http\Controllers\Boats;

use App\Data\Boats\BoatCrewMemberData;
use App\Data\Boats\BoatData;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatBoardRequest;
use App\Models\Boat;
use App\Models\BoatCrewMember;
use App\Models\Property;
use App\Services\Boats\BoatBoard;
use App\Services\Boats\BoatTripUsage;
use App\Services\Reservations\ReservationFormOptions;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Boats board: the boats of one date as groups, one per sailing, for the front desk. See docs/24-boats/05-boats-board.md.
 */
class BoatBoardController extends Controller
{
    public function index(BoatBoardRequest $request, Property $property, BoatBoard $board, BoatTripUsage $usage, ReservationFormOptions $options): Response
    {
        $user = $request->user();
        $businessDate = $usage->businessDate($property);
        $date = $request->input('date') ? CarbonImmutable::createFromFormat('Y-m-d', (string) $request->input('date'))->startOfDay() : $businessDate;
        $canManageSettings = $user->can(PermissionEnum::SettingsManage->value);
        $canManage = $user->can(PermissionEnum::ReservationsManage->value);
        $hasDepartures = $board->hasDepartures($property);

        return Inertia::render('boats/index', [
            'date' => $date->toDateString(),
            'today' => $businessDate->toDateString(),
            'isPast' => $date->toDateString() < $businessDate->toDateString(),
            'direction' => $request->direction(),
            'groups' => $board->groups($property, $date, $request->direction()),
            'hasDepartures' => $hasDepartures,
            'boats' => Boat::query()->where('property_id', $property->id)->orderBy('name')->get()->map(fn (Boat $boat): BoatData => BoatData::fromModel($boat))->all(),
            'crew' => BoatCrewMember::query()->where('property_id', $property->id)->orderBy('name')->get()->map(fn (BoatCrewMember $member): BoatCrewMemberData => BoatCrewMemberData::fromModel($member))->all(),
            'boatDepartures' => Inertia::optional(fn () => $options->boatDepartures($property)),
            'can' => [
                'manage' => $canManage,
                'export' => $user->can(PermissionEnum::ReportsView->value),
            ],
            'settingsUrl' => ! $hasDepartures && $canManageSettings
                ? route('settings.boats.departures.index', ['property' => $property->code], absolute: false)
                : null,
        ]);
    }
}
