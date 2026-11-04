<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\ApiToken;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskLookupLockout;
use App\Services\Kiosk\KioskStays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base of the kiosk controllers that work on a stay by its id. The lookup is the one rule of every by-id call: the
 * stay must be in the token's property and inside the caller's check-in or check-out window; an id that does not qualify answers
 * `booking_not_found`, the same for a missing stay, another property's stay and one outside the window. Ids are
 * guessable, so each lookup takes an attempt from the token's lockout (the one `bookings/find` uses) and gives it back
 * when it finds a stay: a token that keeps missing is answered `rate_limited` whatever it asks.
 */
abstract class KioskStayController extends Controller
{
    public function __construct(protected KioskStays $stays, protected KioskLookupLockout $lockout) {}

    protected function token(Request $request): ApiToken
    {
        /** @var ApiToken */
        return $request->attributes->get('api_token');
    }

    /**
     * @param  list<UnitStatus>  $statuses
     * @param  bool  $kioskCheckInOnly  A stay that is already CheckedIn counts only when a kiosk checked it in, so a
     *                                  guessed id cannot reach the room or key of a guest the front desk checked in.
     */
    protected function stay(Request $request, string $id, array $statuses = [UnitStatus::Confirmed], bool $kioskCheckInOnly = false, bool $checkOut = false): ReservationUnit|JsonResponse
    {
        $token = $this->token($request);

        if (! $this->lockout->attempt($token)) {
            return ApiError::response('rate_limited', __('Too many requests. Wait a moment and try again.'), 429, ['Retry-After' => (string) $this->lockout->retryAfter($token)]);
        }

        $stay = ctype_digit($id) ? $this->stays->find($token->property, (int) $id, $statuses, $checkOut) : null;

        if ($stay !== null && $kioskCheckInOnly && $stay->status === UnitStatus::CheckedIn && ! $this->stays->checkedInByKiosk($stay)) {
            $stay = null;
        }

        if ($stay !== null && $stay->status === UnitStatus::CheckedOut && ! $this->stays->checkedOutByKiosk($stay, $token)) {
            $stay = null;
        }

        if ($stay === null) {
            return $this->notFound();
        }

        $this->lockout->refund($token);

        return $stay;
    }

    protected function notFound(): JsonResponse
    {
        return ApiError::response('booking_not_found', __('We could not find that booking.'), 404);
    }
}
