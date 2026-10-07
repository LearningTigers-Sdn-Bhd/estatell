<?php

namespace App\Http\Controllers\NightAudit;

use App\Actions\NightAudit\NightAuditResult;
use App\Actions\NightAudit\RunAuditFix;
use App\Actions\NightAudit\Steps\ResolveOverdueDepartures;
use App\Data\NightAudit\NightAuditSheetData;
use App\Enums\AuditFix;
use App\Enums\AuditTrigger;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\NightAudit\RunAuditFixRequest;
use App\Http\Requests\NightAudit\RunNightAuditRequest;
use App\Jobs\RunNightAuditJob;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\ReadinessAdvice;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NightAuditController extends Controller
{
    /**
     * The Run Night Audit sheet as JSON: the date, the blockers and warnings, and the audit progress.
     */
    public function show(Request $request, Property $property, AuditReadiness $readiness, CurrentBusinessDate $currentBusinessDate, ReadinessAdvice $advice): JsonResponse
    {
        return response()->json(NightAuditSheetData::forProperty($property, $readiness, $currentBusinessDate, $advice, $request->user()->can(PermissionEnum::NightAuditForce->value)));
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
     * Run one fix of the sheet on the units of a reservation, or on the one unit in `unit_id`. A unit the Action
     * refuses does not stop the others: the answer says how many were done and why the rest were not.
     */
    public function fix(RunAuditFixRequest $request, Property $property, Reservation $reservation, AuditFix $fix, RunAuditFix $runAuditFix, ReservationRefusals $refusals): JsonResponse
    {
        $result = $runAuditFix($request->user(), $property, $reservation, $fix, $request->integer('unit_id') ?: null);

        if ($result->isNothingToDo()) {
            return $this->refused(__('There is nothing left to fix here. The list has been refreshed.'));
        }

        if ($result->refusals !== []) {
            $code = array_values($result->refusals)[0];
            $why = $code === ResolveOverdueDepartures::MESSAGE_PAST_DEPARTURE
                ? __('The unit is past its departure date. Check it out instead.')
                : ($code === 'no_free_unit'
                    ? __('No room or bed is free for the whole stay. Move the unit from its reservation.')
                    : array_values($refusals->unitErrors($code))[0]);

            return $this->refused($result->done > 0 ? __(':done fixed. The rest could not be: :why', ['done' => $result->done, 'why' => $why]) : $why);
        }

        return response()->json(['done' => $result->done]);
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['form' => [$message]]], 422);
    }
}
