<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\Kiosk\BookWalkIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\WalkInRequest;
use App\Http\Resources\Kiosk\V1\KioskWalkInHoldResource;
use App\Http\Resources\Kiosk\V1\KioskWalkInQuoteResource;
use App\Http\Responses\ApiError;
use App\Models\ApiToken;
use App\Services\Kiosk\KioskWalkIn;
use Illuminate\Http\JsonResponse;

class WalkInController extends Controller
{
    public function quote(WalkInRequest $request, KioskWalkIn $walkIn): KioskWalkInQuoteResource
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        return new KioskWalkInQuoteResource($walkIn->offers($token->property, $request->search()));
    }

    public function book(WalkInRequest $request, BookWalkIn $book): KioskWalkInHoldResource|JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');
        $result = $book($token, $request->search(), $request->integer('room_type_id'));

        return match ($result->refusal) {
            null => new KioskWalkInHoldResource($result),
            'no_room_available' => ApiError::response('no_room_available', __('No room is free for those dates. Please see the front desk.'), 409),
            default => ApiError::response('server_error', __('Booking is not available. Please see the front desk.'), 409),
        };
    }
}
