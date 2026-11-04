<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\FindBookingRequest;
use App\Http\Resources\Kiosk\V1\KioskBookingResource;
use App\Http\Responses\ApiError;
use App\Models\ApiToken;
use App\Services\Kiosk\KioskBookingFinder;
use App\Services\Kiosk\KioskBookingLookup;
use App\Services\Kiosk\KioskLookupLockout;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    public function find(FindBookingRequest $request, KioskBookingFinder $finder, KioskLookupLockout $lockout): KioskBookingResource|JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if (! $lockout->attempt($token)) {
            return ApiError::response('rate_limited', __('Too many requests. Wait a moment and try again.'), 429, ['Retry-After' => (string) $lockout->retryAfter($token)]);
        }

        $lookup = $request->filled('reference')
            ? $finder->byReference($token->property, $request->string('reference')->toString())
            : $finder->byQrToken($token->property, $request->string('qr_token')->toString());

        if ($lookup->refusal === null) {
            $lockout->refund($token);
        }

        return match ($lookup->refusal) {
            null => new KioskBookingResource($lookup),
            'hold_expired' => ApiError::response('hold_expired', __('This room hold has expired. Please start again.'), 409),
            KioskBookingLookup::ARRIVAL_NOT_REACHED => ApiError::response($lookup->refusal, __('Check-in is not open for this booking yet.'), 409),
            KioskBookingLookup::AMBIGUOUS => ApiError::response('server_error', __('Please see the front desk to find your stay.'), 409),
            KioskBookingLookup::NOT_OPEN => ApiError::response('server_error', __('Check-in is not open yet. Please see the front desk.'), 409),
            default => ApiError::response(KioskBookingLookup::BOOKING_NOT_FOUND, __('We could not find that booking.'), 404),
        };
    }
}
