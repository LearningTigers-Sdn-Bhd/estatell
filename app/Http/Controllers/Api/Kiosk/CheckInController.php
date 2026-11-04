<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\Kiosk\CheckInStay;
use App\Enums\KioskKeyMode;
use App\Enums\UnitStatus;
use App\Http\Resources\Kiosk\V1\KioskCheckInResource;
use App\Http\Resources\Kiosk\V1\KioskEligibilityResource;
use App\Http\Responses\ApiError;
use App\Services\Kiosk\KioskEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends KioskStayController
{
    public function eligibility(Request $request, string $id, KioskEligibility $eligibility): KioskEligibilityResource|JsonResponse
    {
        $stay = $this->stay($request, $id);

        return $stay instanceof JsonResponse ? $stay : new KioskEligibilityResource($eligibility->reason($stay));
    }

    public function checkIn(Request $request, string $id, CheckInStay $checkInStay): KioskCheckInResource|JsonResponse
    {
        $stay = $this->stay($request, $id, [UnitStatus::Confirmed, UnitStatus::CheckedIn], kioskCheckInOnly: true);

        if ($stay instanceof JsonResponse) {
            return $stay;
        }

        $result = $checkInStay($this->token($request), $stay);

        if ($result->refusal !== null) {
            return ApiError::response($result->refusal, $this->message($result->refusal), 409);
        }

        return new KioskCheckInResource([
            'room' => $result->roomNumber,
            'key_mode' => $stay->property->settings === null ? KioskKeyMode::Pin : $stay->property->settings->kiosk_key_mode,
        ]);
    }

    private function message(string $code): string
    {
        return match ($code) {
            'card_required_for_check_in' => __('The registration card must be signed first.'),
            'deposit_not_paid' => __('Please see the front desk.'),
            'balance_not_settled' => __('Please see the front desk.'),
            'nationality_required' => __('Please confirm your nationality.'),
            'arrival_not_reached' => __('Check-in is not open for this booking yet.'),
            default => __('Please see the front desk.'),
        };
    }
}
