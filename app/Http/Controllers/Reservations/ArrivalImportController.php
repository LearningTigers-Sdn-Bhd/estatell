<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\ImportArrivals;
use App\Data\Property\CurrencyData;
use App\Enums\PermissionEnum;
use App\Enums\SpreadsheetFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\PreviewArrivalImportRequest;
use App\Http\Requests\Reservations\StoreArrivalImportRequest;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use App\Services\Reservations\ArrivalImportPlanner;
use App\Services\Reservations\ArrivalImportReader;
use App\Services\Reservations\ArrivalImportTemplate;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reservations › Import arrivals. See docs/12-reservations/13-arrival-import.md.
 */
class ArrivalImportController extends Controller
{
    public function create(Property $property, CurrentBusinessDate $businessDate, FirstBusinessDate $firstBusinessDate, MigrationWindow $migrationWindow): Response
    {
        return Inertia::render('reservations/import', [
            'openBusinessDate' => $businessDate->date($property)?->toDateString(),
            'goLiveDate' => $firstBusinessDate->date($property)?->toDateString(),
            'migrationWindowClosesOn' => $migrationWindow->isOpen($property) ? $migrationWindow->closesOn($property)?->toDateString() : null,
            'maxRows' => ArrivalImportReader::MAX_ROWS,
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * Read and plan the uploaded file. Nothing is saved.
     */
    public function preview(PreviewArrivalImportRequest $request, Property $property, ArrivalImportReader $reader, ArrivalImportPlanner $planner): JsonResponse
    {
        return response()->json(['rows' => $planner->plan($property, $reader->read($request->file('file')))]);
    }

    /**
     * Read and plan the file again, then import it, all or nothing.
     */
    public function store(StoreArrivalImportRequest $request, Property $property, ArrivalImportReader $reader, ArrivalImportPlanner $planner, ImportArrivals $importArrivals, ReservationRefusals $refusals): RedirectResponse
    {
        $user = $request->user();
        $file = $request->file('file');

        $result = $importArrivals(
            $user,
            $property,
            $planner->plan($property, $reader->read($file)),
            $request->string('reason')->toString(),
            $file->getClientOriginalName(),
            canOverrideCredit: $user->can(PermissionEnum::CompanyCreditOverride->value),
            withoutCard: $user->can(PermissionEnum::FrontDeskCheckInWithoutCard->value),
        );

        if ($result->isRefused()) {
            $message = $refusals->message((string) $result->refusal, $result->date);

            Inertia::flash('toast', ['type' => 'error', 'message' => $result->line === null ? $message : __('Row :line: :message', ['line' => $result->line, 'message' => $message])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count booking imported (:units rooms).|:count bookings imported (:units rooms).', $result->reservations, ['units' => $result->units])]);

        return to_route('reservations.index', $property);
    }

    /**
     * The template file with the estatell columns and one example row.
     */
    public function template(Property $property, SpreadsheetFormat $format, ArrivalImportTemplate $template): BinaryFileResponse
    {
        return response()
            ->download($template->write($format), "arrival-import.{$format->value}")
            ->deleteFileAfterSend();
    }
}
