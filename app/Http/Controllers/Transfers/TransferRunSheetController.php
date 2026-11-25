<?php

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\AssignTransfer;
use App\Data\Transfers\TransferDriverData;
use App\Data\Transfers\TransferVehicleData;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\TransferDriver;
use App\Models\TransferVehicle;
use App\Models\UnitTransfer;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Transfers\TransferRefusals;
use App\Services\Transfers\TransferRunSheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Front desk › Transfers: the run sheet of one date, and picking each transfer's vehicle and driver. The page also gets
 * the saved Transfer manifest layout for its Export menu and Columns Sheet.
 * See docs/35-transfers/01-land-transfers.md#run-sheet.
 */
class TransferRunSheetController extends Controller
{
    public function index(Request $request, Property $property, TransferRunSheet $runSheet, CurrentBusinessDate $businessDate, PropertyToday $today, ReportLayoutResolver $layoutResolver): Response
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $businessDay = $businessDate->date($property) ?? $today->date($property);
        $date = isset($validated['date']) ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay() : $businessDay;
        $user = $request->user();

        return Inertia::render('transfers/index', [
            'date' => $date->toDateString(),
            'today' => $businessDay->toDateString(),
            'rows' => $runSheet->rows($property, $date, $user->can(PermissionEnum::GuestsView->value)),
            'canAssign' => $user->can(PermissionEnum::ReservationsManage->value),
            'canEditColumns' => $user->can(PermissionEnum::SettingsManage->value),
            'layout' => $layoutResolver->resolve($property, ReportKey::TransferManifest),
            'orientationOptions' => PageOrientation::options(),
            'vehicles' => TransferVehicle::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (TransferVehicle $vehicle): TransferVehicleData => TransferVehicleData::fromModel($vehicle))
                ->all(),
            'drivers' => TransferDriver::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (TransferDriver $driver): TransferDriverData => TransferDriverData::fromModel($driver))
                ->all(),
        ]);
    }

    public function assign(Request $request, Property $property, UnitTransfer $transfer, AssignTransfer $assign, TransferRefusals $refusals): RedirectResponse
    {
        abort_unless($transfer->property_id === $property->id, 404);

        $validated = $request->validate([
            'transfer_vehicle_id' => ['nullable', 'integer'],
            'transfer_driver_id' => ['nullable', 'integer'],
        ]);

        $result = $assign(
            $request->user(),
            $transfer,
            isset($validated['transfer_vehicle_id']) ? (int) $validated['transfer_vehicle_id'] : null,
            isset($validated['transfer_driver_id']) ? (int) $validated['transfer_driver_id'] : null,
        );

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) current($refusals->errors((string) $result->refusal))]);
        }

        return back();
    }
}
