<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\Kiosk\CorrectStayGuest;
use App\Actions\Kiosk\SaveStayIdentity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\StoreStayIdentityRequest;
use App\Http\Requests\Kiosk\UpdateStayGuestRequest;
use App\Http\Resources\Kiosk\V1\KioskAcceptedResource;
use App\Http\Resources\Kiosk\V1\KioskIdentityResource;
use App\Http\Responses\ApiError;
use App\Models\ApiToken;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StayController extends Controller
{
    public function identity(StoreStayIdentityRequest $request, string $id, KioskStays $stays, SaveStayIdentity $saveIdentity): KioskIdentityResource|JsonResponse
    {
        $token = $this->token($request);
        $stay = $this->stay($stays, $token, $id);

        return $stay === null
            ? $this->notFound()
            : new KioskIdentityResource($saveIdentity($token, $stay, $request->toIdentityData()));
    }

    public function guest(UpdateStayGuestRequest $request, string $id, KioskStays $stays, CorrectStayGuest $correctGuest): KioskAcceptedResource|JsonResponse
    {
        $token = $this->token($request);
        $stay = $this->stay($stays, $token, $id);

        return $stay === null
            ? $this->notFound()
            : new KioskAcceptedResource($correctGuest($token, $stay, $request->fullName(), $request->nationalityCode()));
    }

    private function token(Request $request): ApiToken
    {
        /** @var ApiToken */
        return $request->attributes->get('api_token');
    }

    /**
     * A stay of another property, a stay that is not open to check in and an id that is not a number all give null.
     */
    private function stay(KioskStays $stays, ApiToken $token, string $id): ?ReservationUnit
    {
        return ctype_digit($id) ? $stays->find($token->property, (int) $id) : null;
    }

    private function notFound(): JsonResponse
    {
        return ApiError::response('booking_not_found', __('We could not find that booking.'), 404);
    }
}
