<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\Kiosk\RegisterStayCard;
use App\Enums\UnitStatus;
use App\Http\Requests\Kiosk\RegisterCardRequest;
use App\Http\Resources\Kiosk\V1\KioskCardRegisteredResource;
use App\Http\Resources\Kiosk\V1\KioskPinResource;
use App\Http\Responses\ApiError;
use App\Services\Kiosk\KioskPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The room key after check-in. Both calls only serve a stay this kiosk checked in a moment ago (`KioskStays::KEY_WINDOW_MINUTES`).
 */
class KeyController extends KioskStayController
{
    public function pin(Request $request, string $id, KioskPin $pin): JsonResponse
    {
        $stay = $this->stay($request, $id, [UnitStatus::CheckedIn], kioskCheckInOnly: true);

        return $stay instanceof JsonResponse
            ? $stay
            : (new KioskPinResource($pin->for($stay)))->response()->header('Cache-Control', 'no-store');
    }

    public function card(RegisterCardRequest $request, string $id, RegisterStayCard $registerCard): KioskCardRegisteredResource|JsonResponse
    {
        $stay = $this->stay($request, $id, [UnitStatus::CheckedIn], kioskCheckInOnly: true);

        if ($stay instanceof JsonResponse) {
            return $stay;
        }

        $result = $registerCard($stay, $request->string('uid')->toString());

        return match ($result->refusal) {
            null => new KioskCardRegisteredResource($result),
            'card_unknown' => ApiError::response('card_unknown', __('This card is not known. Please see the front desk.'), 404),
            default => ApiError::response('lock_unavailable', __('The door lock cannot be reached. Please see the front desk.'), 503),
        };
    }
}
