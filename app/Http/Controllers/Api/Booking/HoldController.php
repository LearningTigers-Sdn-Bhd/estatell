<?php

namespace App\Http\Controllers\Api\Booking;

use App\Actions\BookingEngine\BookingHoldPaymentResult;
use App\Actions\BookingEngine\CreateBookingHold;
use App\Actions\BookingEngine\ReleaseBookingHold;
use App\Actions\BookingEngine\StartBookingHoldPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingHoldRequest;
use App\Http\Resources\Booking\V1\BookingHoldPaymentResource;
use App\Http\Resources\Booking\V1\BookingHoldResource;
use App\Http\Responses\ApiError;
use App\Models\BookingClient;
use App\Models\BookingHold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    public function store(StoreBookingHoldRequest $request, CreateBookingHold $createBookingHold): JsonResponse
    {
        $property = $request->attributes->get('booking_property');
        $result = $createBookingHold($request->attributes->get('booking_client'), $property, $request->holdData());

        if ($result->hold === null) {
            return ApiError::response((string) $result->refusal, (string) $result->message, 422, details: array_filter(['current_total' => $result->currentTotal], fn (?string $value): bool => $value !== null));
        }

        return response()->json([
            ...(new BookingHoldResource($result->hold))->resolve($request),
            'pay_at_property' => (string) $result->deskTotal?->getAmount(),
        ], 201);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $hold = $this->hold($request, $token);

        return $hold === null ? $this->notFound() : response()->json((new BookingHoldResource($hold))->resolve($request));
    }

    public function destroy(Request $request, string $token, ReleaseBookingHold $releaseBookingHold): JsonResponse
    {
        $hold = $this->hold($request, $token);

        if ($hold === null) {
            return $this->notFound();
        }

        if (! $releaseBookingHold($hold)) {
            return ApiError::response('hold_not_open', __('This hold can no longer be released.'), 422);
        }

        return response()->json((new BookingHoldResource($hold))->resolve($request));
    }

    /**
     * Start paying for a hold. `payment_unavailable` answers 503, so the client can retry with the same key.
     */
    public function payment(Request $request, string $token, StartBookingHoldPayment $startBookingHoldPayment): JsonResponse
    {
        $hold = $this->hold($request, $token);

        if ($hold === null) {
            return $this->notFound();
        }

        $result = $startBookingHoldPayment($request->attributes->get('booking_client'), $hold);

        if ($result->payment === null) {
            $status = match ($result->refusal) {
                BookingHoldPaymentResult::REFUSAL_PROPERTY_NOT_FOUND => 404,
                BookingHoldPaymentResult::REFUSAL_PAYMENT_UNAVAILABLE => 503,
                default => 422,
            };

            return ApiError::response((string) $result->refusal, (string) $result->message, $status);
        }

        return response()->json((new BookingHoldPaymentResource($result->payment))->resolve($request));
    }

    /**
     * A client reaches only the holds it made.
     */
    private function hold(Request $request, string $token): ?BookingHold
    {
        /** @var BookingClient $client */
        $client = $request->attributes->get('booking_client');

        return BookingHold::query()->where('booking_client_id', $client->id)->where('token', $token)->first();
    }

    private function notFound(): JsonResponse
    {
        return ApiError::response('hold_not_found', __('We could not find that hold.'), 404);
    }
}
