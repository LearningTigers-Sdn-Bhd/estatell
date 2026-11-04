<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\Kiosk\CorrectStayGuest;
use App\Actions\Kiosk\SaveStayIdentity;
use App\Http\Requests\Kiosk\StoreStayIdentityRequest;
use App\Http\Requests\Kiosk\UpdateStayGuestRequest;
use App\Http\Resources\Kiosk\V1\KioskAcceptedResource;
use App\Http\Resources\Kiosk\V1\KioskIdentityResource;
use Illuminate\Http\JsonResponse;

class StayController extends KioskStayController
{
    public function identity(StoreStayIdentityRequest $request, string $id, SaveStayIdentity $saveIdentity): KioskIdentityResource|JsonResponse
    {
        $stay = $this->stay($request, $id);

        return $stay instanceof JsonResponse
            ? $stay
            : new KioskIdentityResource($saveIdentity($this->token($request), $stay, $request->toIdentityData()));
    }

    public function guest(UpdateStayGuestRequest $request, string $id, CorrectStayGuest $correctGuest): KioskAcceptedResource|JsonResponse
    {
        $stay = $this->stay($request, $id);

        return $stay instanceof JsonResponse
            ? $stay
            : new KioskAcceptedResource($correctGuest($this->token($request), $stay, $request->fullName(), $request->nationalityCode()));
    }
}
