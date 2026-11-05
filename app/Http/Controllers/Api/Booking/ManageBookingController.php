<?php

namespace App\Http\Controllers\Api\Booking;

use App\Actions\BookingEngine\CancelBookingOnline;
use App\Enums\BookingHoldStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelOnlineBookingRequest;
use App\Http\Resources\Booking\V1\ManagedBookingResource;
use App\Http\Responses\ApiError;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Services\BookingEngine\OnlineCancellationQuotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guest's Manage booking endpoints. The hold's long random token is the booking token. They do not check the property's
 * reach, because a booking made before the flag went off still works. See docs/29-booking-engine/02-api.md#manage-booking.
 */
class ManageBookingController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        $hold = $this->booking($request, $token);

        return $hold === null ? $this->notFound() : response()->json((new ManagedBookingResource($hold))->resolve($request));
    }

    public function cancellation(Request $request, string $token, OnlineCancellationQuotes $quotes): JsonResponse
    {
        $hold = $this->booking($request, $token);

        if ($hold === null) {
            return $this->notFound();
        }

        $quote = $quotes->for($hold->reservation);

        return response()->json([
            'can_cancel' => $quote->canCancel(),
            'refusal' => $quote->refusal,
            'message' => $quote->message(),
            'paid' => $quote->paid,
            'fee' => $quote->fee,
            'tax' => $quote->tax,
            'refund' => $quote->refund,
        ]);
    }

    public function cancel(CancelOnlineBookingRequest $request, string $token, CancelBookingOnline $cancelBookingOnline): JsonResponse
    {
        $hold = $this->booking($request, $token);

        if ($hold === null) {
            return $this->notFound();
        }

        $result = $cancelBookingOnline($hold, $request->expectedRefund());

        if ($result->isRefused()) {
            return ApiError::response((string) $result->refusal, (string) $result->message, 422, details: array_filter(['current_refund' => $result->currentRefund], fn (?string $value): bool => $value !== null));
        }

        return response()->json(['status' => $hold->reservation->refresh()->status->value, 'refund' => $result->refund]);
    }

    /**
     * A client reaches only the bookings it made. A hold that is not confirmed yet has no booking.
     */
    private function booking(Request $request, string $token): ?BookingHold
    {
        /** @var BookingClient $client */
        $client = $request->attributes->get('booking_client');

        return BookingHold::query()
            ->with(['reservation.booker', 'reservation.units.roomType'])
            ->where('booking_client_id', $client->id)
            ->where('token', $token)
            ->where('status', BookingHoldStatus::Confirmed)
            ->whereNotNull('reservation_id')
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return ApiError::response('booking_not_found', __('We could not find that booking.'), 404);
    }
}
