<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\ChangeUnitDates;
use App\Actions\FrontDesk\CheckInAndCollect;
use App\Actions\FrontDesk\CheckOutAndRefund;
use App\Actions\FrontDesk\ExtendDepartureTime;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\FrontDesk\ReinstateUnit;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ChangeUnitDatesRequest;
use App\Http\Requests\Reservations\CheckInUnitRequest;
use App\Http\Requests\Reservations\CheckOutUnitRequest;
use App\Http\Requests\Reservations\LateCheckOutRequest;
use App\Http\Requests\Reservations\ReinstateUnitRequest;
use App\Http\Requests\Reservations\UnitPolicyFeeRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Payments\PaymentRefusals;
use App\Services\Reservations\ReservationRefusals;
use App\Services\Reservations\UnitActionPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The unit ⋯ menu actions of the reservation page. A preview route reads what a form shows when it opens and
 * changes nothing. An action route sends choices to one Action, which works out every amount again.
 */
class ReservationUnitStayController extends Controller
{
    public function cancelPreview(Property $property, Reservation $reservation, ReservationUnit $unit, UnitActionPreview $preview): JsonResponse
    {
        return response()->json($preview->fee($unit, SystemTransactionCode::CancellationRevenue));
    }

    public function noShowPreview(Property $property, Reservation $reservation, ReservationUnit $unit, UnitActionPreview $preview): JsonResponse
    {
        return response()->json($preview->fee($unit, SystemTransactionCode::NoShowRevenue));
    }

    public function reinstatePreview(Property $property, Reservation $reservation, ReservationUnit $unit, UnitActionPreview $preview): JsonResponse
    {
        return response()->json($preview->reinstate($unit));
    }

    public function checkInPreview(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, UnitActionPreview $preview): JsonResponse
    {
        return response()->json($preview->checkIn($unit, $request->user()));
    }

    public function checkOutPreview(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, UnitActionPreview $preview): JsonResponse
    {
        return response()->json($preview->checkOut($unit, $request->user()));
    }

    public function lateCheckOutPreview(Property $property, Reservation $reservation, ReservationUnit $unit, UnitActionPreview $preview): JsonResponse
    {
        return response()->json($preview->lateCheckOut($unit));
    }

    public function datesPreview(ChangeUnitDatesRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, UnitActionPreview $preview): JsonResponse
    {
        return response()->json($preview->dates($unit, $request->arrival(), $request->departure()));
    }

    /**
     * Check one unit in and, with Collect now, take the taxes flagged for check-in and the security deposit. A
     * refused payment leaves the unit checked in and goes back to the Dialog as an error on the method or reference.
     */
    public function checkIn(CheckInUnitRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, CheckInAndCollect $checkInAndCollect, ReservationRefusals $refusals, PaymentRefusals $paymentRefusals): RedirectResponse
    {
        $result = $checkInAndCollect($request->user(), $unit, $request->nationality(), $request->withoutCard(), $request->creditOverrideReason(), $request->collectNow());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation]);

        if ($result->stay->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->unitErrors((string) $result->stay->refusal, canOverrideCard: $request->user()->can(PermissionEnum::FrontDeskCheckInWithoutCard->value)));
        }

        if ($result->paymentRefusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The unit is checked in, but the payment was refused.')]);

            return back(fallback: $page)->withErrors($paymentRefusals->errors($result->paymentRefusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unit checked in.')]);

        return back(fallback: $page);
    }

    /**
     * Check one unit out, after giving back the security deposit when the form asks for it.
     */
    public function checkOut(CheckOutUnitRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, CheckOutAndRefund $checkOutAndRefund, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $checkOutAndRefund($request->user(), $unit, $request->refundAmount(), $request->reference(), $request->string('idempotency_key')->toString());

        return $this->respond($property, $reservation, $result->refusal, $refusals, __('Unit checked out.'));
    }

    /**
     * Late check-out: a later departure time and its policy fee.
     */
    public function lateCheckOut(LateCheckOutRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, ExtendDepartureTime $extendDepartureTime, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $extendDepartureTime($request->user(), $unit, $request->string('new_time')->toString(), $request->doNotCharge(), $request->reason(), $request->expectedFee());

        return $this->respond($property, $reservation, $result->refusal, $refusals, __('Departure time extended.'));
    }

    /**
     * Cancel one unit and post the cancellation fee. A refusal goes back to the Dialog as an error.
     */
    public function cancel(UnitPolicyFeeRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, CancelUnit $cancelUnit, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $cancelUnit($request->user(), $unit, $request->doNotCharge(), $request->reason(), $request->expectedFee(), $request->boolean('remove_add_ons'));

        return $this->respond($property, $reservation, $result->refusal, $refusals, __('Unit cancelled.'));
    }

    /**
     * Mark one unit as a no-show and post the no-show fee.
     */
    public function noShow(UnitPolicyFeeRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, MarkNoShow $markNoShow, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $markNoShow($request->user(), $unit, $request->doNotCharge(), $request->reason(), $request->expectedFee(), $request->boolean('remove_add_ons'));

        return $this->respond($property, $reservation, $result->refusal, $refusals, __('Unit marked as a no-show.'));
    }

    /**
     * Move a cancelled or no-show unit back to Confirmed, priced again.
     */
    public function reinstate(ReinstateUnitRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, ReinstateUnit $reinstateUnit, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $reinstateUnit($request->user(), $unit, $request->string('reason')->toString());

        return $this->respond($property, $reservation, $result->refusal, $refusals, __('Unit reinstated. Assign its rooms again.'));
    }

    /**
     * Change the arrival and departure of a unit. A shorter stay posts the early departure fee.
     */
    public function changeDates(ChangeUnitDatesRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, ChangeUnitDates $changeUnitDates, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $changeUnitDates(
            $request->user(),
            $unit,
            $request->arrival(),
            $request->departure(),
            $request->doNotCharge(),
            $request->reason(),
            $request->expectedFee(),
            $request->restrictionOverrideReason(),
        );

        return $this->respond($property, $reservation, $result->refusal, $refusals, __('Dates changed.'), $result->unassignedNights > 0
            ? trans_choice(':count added night needs a room.|:count added nights need a room.', $result->unassignedNights)
            : null);
    }

    private function respond(Property $property, Reservation $reservation, ?string $refusal, ReservationRefusals $refusals, string $message, ?string $note = null): RedirectResponse
    {
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation]);

        if ($refusal !== null) {
            return back(fallback: $page)->withErrors($refusals->unitErrors($refusal, canOverrideRestrictions: request()->user()->can(PermissionEnum::RatesOverrideRestrictions->value)));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $note === null ? $message : $message.' '.$note]);

        return back(fallback: $page);
    }
}
