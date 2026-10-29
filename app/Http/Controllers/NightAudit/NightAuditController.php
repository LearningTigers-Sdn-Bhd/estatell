<?php

namespace App\Http\Controllers\NightAudit;

use App\Actions\NightAudit\DismissAuditException;
use App\Actions\NightAudit\FixPackageNightPrice;
use App\Actions\NightAudit\NightAuditResult;
use App\Actions\NightAudit\RetryRoomCharge;
use App\Actions\NightAudit\RunAuditFix;
use App\Actions\NightAudit\Steps\ResolveOverdueDepartures;
use App\Data\NightAudit\NightAuditReservationData;
use App\Data\NightAudit\NightAuditSheetData;
use App\Data\NightAudit\PackageNightRespreadData;
use App\Enums\AuditFix;
use App\Enums\AuditTrigger;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\NightAudit\DismissAuditExceptionRequest;
use App\Http\Requests\NightAudit\RunAuditFixRequest;
use App\Http\Requests\NightAudit\RunNightAuditRequest;
use App\Jobs\RunNightAuditJob;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\DueAudits;
use App\Services\NightAudit\PackageNightRespread;
use App\Services\NightAudit\ReadinessAdvice;
use App\Services\Reservations\ReservationFormOptions;
use App\Services\Reservations\ReservationOverview;
use App\Services\Reservations\ReservationRefusals;
use App\Services\Reservations\UnitActionMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NightAuditController extends Controller
{
    /**
     * The Run Night Audit sheet as JSON: the date, the blockers and warnings, and the audit progress.
     */
    public function show(Request $request, Property $property, AuditReadiness $readiness, CurrentBusinessDate $currentBusinessDate, ReadinessAdvice $advice, DueAudits $dueAudits): JsonResponse
    {
        return response()->json(NightAuditSheetData::forProperty($property, $readiness, $currentBusinessDate, $advice, $dueAudits, $request->user()));
    }

    /**
     * Queue the audit. A date with blockers is refused unless the run is forced, a date that has not ended is refused
     * even when forced, and a date that is already Auditing only resumes a Failed audit, which `RunNightAudit` checks.
     */
    public function run(RunNightAuditRequest $request, Property $property, AuditReadiness $readiness, CurrentBusinessDate $currentBusinessDate, DueAudits $dueAudits): JsonResponse
    {
        $businessDate = $currentBusinessDate->get($property);

        if ($businessDate === null) {
            return $this->refused(__('This property has no business date yet.'));
        }

        $gate = $readiness->gate($property, ignoreBlockers: $request->boolean('force'));

        if ($gate === NightAuditResult::REFUSAL_BUSINESS_DATE_NOT_ENDED) {
            $dueAt = $dueAudits->dueAt($property, $businessDate->date);

            return $this->refused($dueAt === null
                ? __(':date has not ended yet. Run the audit after midnight.', ['date' => $businessDate->date->format('j M')])
                : __(':date has not ended yet. It closes automatically at :time on :day.', ['date' => $businessDate->date->format('j M'), 'time' => $dueAt->format('H:i'), 'day' => $dueAt->format('j M')]));
        }

        if ($gate !== null) {
            return $this->refused(__('Fix the items under "Fix before running" first.'));
        }

        RunNightAuditJob::dispatch($property, AuditTrigger::Manual, $request->user(), $request->boolean('force'), $request->input('reason'));

        return response()->json(['queued' => true], 202);
    }

    /**
     * The data the check-in, check-in-all and check-out Dialogs need when the sheet opens one over itself.
     */
    public function reservation(Request $request, Property $property, Reservation $reservation, ReservationOverview $overview, UnitActionMenu $actionMenu, ReservationFormOptions $options): JsonResponse
    {
        return response()->json(NightAuditReservationData::forReservation($reservation, $property, $request->user(), $overview, $actionMenu, $options));
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

    /**
     * Post the night a room charge exception left unposted. A refusal answers with its reason in words.
     */
    public function retry(Request $request, Property $property, NightAuditException $nightAuditException, RetryRoomCharge $retryRoomCharge, ReadinessAdvice $advice): JsonResponse
    {
        $result = $retryRoomCharge($request->user(), $nightAuditException);

        if ($result->isRefused()) {
            return $this->refused($result->refusal === RetryRoomCharge::REFUSAL_EXCEPTION_NOT_RETRIED
                ? __('There is nothing left to fix here. The list has been refreshed.')
                : $advice->reason((string) $result->refusal));
        }

        return response()->json(['done' => 1]);
    }

    public function fixPricePreview(Request $request, Property $property, NightAuditException $nightAuditException, PackageNightRespread $respread, ReadinessAdvice $advice): JsonResponse
    {
        $spread = $respread->forException($nightAuditException);

        if ($spread->refusal !== null) {
            return $this->refused($advice->reason($spread->refusal, $spread->remaining));
        }

        return response()->json(PackageNightRespreadData::fromSpread($nightAuditException, $spread, $request->user()->can(PermissionEnum::ReservationsChangePrice->value)));
    }

    public function fixPrice(Request $request, Property $property, NightAuditException $nightAuditException, FixPackageNightPrice $fixPackageNightPrice, ReadinessAdvice $advice): JsonResponse
    {
        $result = $fixPackageNightPrice($request->user(), $nightAuditException, $request->user()->can(PermissionEnum::ReservationsChangePrice->value));

        if ($result->isRefused()) {
            return $this->refused($advice->reason((string) $result->refusal));
        }

        return response()->json(['done' => 1]);
    }

    public function dismiss(DismissAuditExceptionRequest $request, Property $property, NightAuditException $nightAuditException, DismissAuditException $dismissAuditException, ReadinessAdvice $advice): JsonResponse
    {
        $result = $dismissAuditException($request->user(), $nightAuditException, $request->validated('reason'));

        if ($result->isRefused()) {
            return $this->refused($advice->reason((string) $result->refusal));
        }

        return response()->json(['done' => 1]);
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['form' => [$message]]], 422);
    }
}
