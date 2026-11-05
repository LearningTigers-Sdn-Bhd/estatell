<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Kiosk\CheckOutStay;
use App\Enums\UnitStatus;
use App\Http\Requests\Kiosk\CheckOutRequest;
use App\Http\Requests\Kiosk\RegisterCardRequest;
use App\Http\Resources\Kiosk\V1\KioskCheckOutResource;
use App\Http\Responses\ApiError;
use App\Services\Kiosk\KioskCheckOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckOutController extends KioskStayController
{
    public function lookup(RegisterCardRequest $request, KioskCheckOut $checkOut): KioskCheckOutResource|JsonResponse
    {
        $kiosk = $this->kiosk($request);

        if (! $this->lockout->attempt($kiosk)) {
            return $this->rateLimited($request);
        }

        $lookup = $checkOut->lookup($kiosk->property, $request->string('uid')->toString());

        if ($lookup['known']) {
            $this->lockout->refund($kiosk);
        }

        return new KioskCheckOutResource($lookup);
    }

    public function folio(Request $request, string $id, KioskCheckOut $checkOut): KioskCheckOutResource|JsonResponse
    {
        $stay = $this->stay($request, $id, [UnitStatus::CheckedIn], checkOut: true);

        return $stay instanceof JsonResponse ? $stay : new KioskCheckOutResource($checkOut->folio($stay));
    }

    public function checkOut(CheckOutRequest $request, string $id, CheckOutStay $checkOut): KioskCheckOutResource|JsonResponse
    {
        $stay = $this->stay($request, $id, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], checkOut: true);

        if ($stay instanceof JsonResponse) {
            return $stay;
        }

        $uid = $request->has('uid') ? $request->string('uid')->toString() : null;
        $kiosk = $this->kiosk($request);

        if ($uid !== null && ! $this->lockout->attempt($kiosk)) {
            return $this->rateLimited($request);
        }

        $result = $checkOut($kiosk, $stay, $uid);

        if ($uid !== null && $result->refusal !== 'card_unknown') {
            $this->lockout->refund($kiosk);
        }

        return match ($result->refusal) {
            null => new KioskCheckOutResource(['checked_out' => true, 'invoice_sent' => false]),
            'booking_not_found' => $this->notFound(),
            'card_unknown' => ApiError::response('card_unknown', __('This card is not known. Please see the front desk.'), 404),
            'lock_unavailable' => ApiError::response('lock_unavailable', __('The door lock cannot be reached. Please see the front desk.'), 503),
            UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED, UnitStayResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES => ApiError::response('balance_not_settled', __('Please settle the balance at the front desk.'), 409),
            default => ApiError::response('server_error', __('Check-out could not be completed. Please see the front desk.'), 409),
        };
    }

    private function rateLimited(Request $request): JsonResponse
    {
        return ApiError::response('rate_limited', __('Too many requests. Wait a moment and try again.'), 429,
            ['Retry-After' => (string) $this->lockout->retryAfter($this->kiosk($request))]);
    }
}
