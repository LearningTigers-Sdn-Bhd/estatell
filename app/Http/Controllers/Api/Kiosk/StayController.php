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
use App\Models\Kiosk;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StayController extends Controller
{
    public function identity(StoreStayIdentityRequest $request, string $id, KioskStays $stays, SaveStayIdentity $saveIdentity): KioskIdentityResource|JsonResponse
    {
        $kiosk = $this->kiosk($request);
        $stay = $this->stay($stays, $kiosk, $id);

        return $stay === null
            ? $this->notFound()
            : new KioskIdentityResource($saveIdentity($kiosk, $stay, $request->toIdentityData()));
    }

    public function guest(UpdateStayGuestRequest $request, string $id, KioskStays $stays, CorrectStayGuest $correctGuest): KioskAcceptedResource|JsonResponse
    {
        $kiosk = $this->kiosk($request);
        $stay = $this->stay($stays, $kiosk, $id);

        return $stay === null
            ? $this->notFound()
            : new KioskAcceptedResource($correctGuest($kiosk, $stay, $request->fullName(), $request->nationalityCode()));
    }

    private function kiosk(Request $request): Kiosk
    {
        /** @var Kiosk */
        return $request->attributes->get('kiosk');
    }

    /**
     * A stay of another property, a stay that is not open to check in and an id that is not a number all give null.
     */
    private function stay(KioskStays $stays, Kiosk $kiosk, string $id): ?ReservationUnit
    {
        return ctype_digit($id) ? $stays->find($kiosk->property, (int) $id) : null;
    }

    private function notFound(): JsonResponse
    {
        return ApiError::response('booking_not_found', __('We could not find that booking.'), 404);
    }
}
