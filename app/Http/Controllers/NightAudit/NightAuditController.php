<?php

namespace App\Http\Controllers\NightAudit;

use App\Actions\FrontDesk\AutoAssignRooms;
use App\Actions\NightAudit\NightAuditResult;
use App\Actions\Reservations\AssignmentResult;
use App\Data\NightAudit\NightAuditSheetData;
use App\Enums\AuditTrigger;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\NightAudit\RunNightAuditRequest;
use App\Jobs\RunNightAuditJob;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NightAuditController extends Controller
{
    /**
     * The Run Night Audit sheet as JSON: the date, the blockers and warnings, and the audit progress.
     */
    public function show(Request $request, Property $property, AuditReadiness $readiness, CurrentBusinessDate $currentBusinessDate): JsonResponse
    {
        return response()->json(NightAuditSheetData::forProperty($property, $readiness, $currentBusinessDate, $request->user()->can(PermissionEnum::NightAuditForce->value)));
    }

    /**
     * Queue the audit. A date with blockers is refused unless the run is forced, and a date that is already Auditing
     * only resumes a Failed audit, which `RunNightAudit` checks.
     */
    public function run(RunNightAuditRequest $request, Property $property, AuditReadiness $readiness, CurrentBusinessDate $currentBusinessDate): JsonResponse
    {
        $businessDate = $currentBusinessDate->get($property);

        if ($businessDate === null) {
            return $this->refused(__('This property has no business date yet.'));
        }

        $gate = $request->boolean('force') ? null : $readiness->gate($property);

        if ($gate !== null) {
            return $this->refused($gate === NightAuditResult::REFUSAL_BUSINESS_DATE_NOT_ENDED
                ? __('The business date has not ended yet. Force close if you are sure.')
                : __('Fix the items under "Fix before running" first.'));
        }

        RunNightAuditJob::dispatch($property, AuditTrigger::Manual, $request->user(), $request->boolean('force'), $request->input('reason'));

        return response()->json(['queued' => true], 202);
    }

    /**
     * Give an in-house unit without a room or bed a free one for the whole stay.
     */
    public function assign(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, AutoAssignRooms $autoAssignRooms, ReservationRefusals $refusals): JsonResponse
    {
        $result = $autoAssignRooms($request->user(), $unit);

        if ($result->isRefused()) {
            return $this->refused($result->refusal === AssignmentResult::REFUSAL_NO_FREE_UNIT
                ? __('No room or bed is free for the whole stay. Move the unit from its reservation.')
                : array_values($refusals->unitErrors((string) $result->refusal, $result->date))[0]);
        }

        return response()->json(['assigned' => true]);
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['form' => [$message]]], 422);
    }
}
