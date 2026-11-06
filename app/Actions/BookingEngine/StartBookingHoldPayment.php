<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingHoldStatus;
use App\Enums\GatewayPaymentStatus;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\GatewayPayment;
use App\Services\BookingEngine\BookingClientReach;
use App\Services\Payments\CurlecGateway;
use App\Services\Payments\CurlecRequestFailed;
use Brick\Money\Money;

/**
 * Create the Curlec order of an open hold, for the amount estatell calculated. A hold has one order: a guest who retries
 * gets the same one, so they cannot pay twice. The order is created before the row is saved, because the call must not
 * hold a database lock. When two requests race, the second order is never shown and cannot be paid. An API only client
 * names an approved return address; a retry may name another, and the guest returns to the last one. The demo client may
 * name any http or https address, so a developer can test from their own machine.
 * See docs/29-booking-engine/04-payment.md#flow and docs/29-booking-engine/09-api-only-mode.md#access.
 */
class StartBookingHoldPayment
{
    public function __construct(private CurlecGateway $gateway, private BookingClientReach $reach) {}

    public function __invoke(BookingClient $client, BookingHold $hold, ?string $returnUrl = null): BookingHoldPaymentResult
    {
        $property = $hold->property;

        if ($this->reach->propertyByCode($client, $property->code) === null) {
            return BookingHoldPaymentResult::refused(BookingHoldPaymentResult::REFUSAL_PROPERTY_NOT_FOUND, __('We could not find that property.'));
        }

        if ($hold->status !== BookingHoldStatus::Held || ! $hold->expires_at->isFuture()) {
            return BookingHoldPaymentResult::refused(BookingHoldPaymentResult::REFUSAL_HOLD_NOT_OPEN, __('This hold is no longer open. Start the booking again.'));
        }

        $returnUrl = $client->type->requiresReturnAddress() || $client->type->acceptsAnyReturnAddress() ? $returnUrl : null;

        if ($client->type->requiresReturnAddress() && ($returnUrl === null || ! $property->bookingReturnAddresses()->where('url', $returnUrl)->exists())) {
            return BookingHoldPaymentResult::refused(BookingHoldPaymentResult::REFUSAL_RETURN_URL_NOT_ALLOWED, __('Use one of the return addresses approved in the booking website settings.'));
        }

        if ($client->type->acceptsAnyReturnAddress() && $returnUrl !== null && ! $this->isOpenReturnAddress($returnUrl)) {
            return BookingHoldPaymentResult::refused(BookingHoldPaymentResult::REFUSAL_RETURN_URL_NOT_ALLOWED, __('Send a full http or https address with no # part, up to 500 characters.'));
        }

        $existing = GatewayPayment::query()->where('booking_hold_id', $hold->id)->first();

        if ($existing !== null) {
            if ($existing->return_url !== $returnUrl) {
                $existing->forceFill(['return_url' => $returnUrl])->save();
            }

            return BookingHoldPaymentResult::started($existing);
        }

        $currency = $property->settings->currency->getCurrencyCode();

        try {
            $orderId = $this->gateway->createOrder(
                Money::of($hold->total_amount, $currency)->getMinorAmount()->toInt(),
                $currency,
                'hold-'.$hold->id,
                $hold->token,
            );
        } catch (CurlecRequestFailed $failed) {
            report($failed);

            return BookingHoldPaymentResult::refused(BookingHoldPaymentResult::REFUSAL_PAYMENT_UNAVAILABLE, __('Online payment is not available at the moment. Try again in a few minutes.'));
        }

        return BookingHoldPaymentResult::started(GatewayPayment::query()->createOrFirst(
            ['booking_hold_id' => $hold->id],
            [
                'property_id' => $hold->property_id,
                'gateway_account' => $this->gateway->account(),
                'order_id' => $orderId,
                'amount' => $hold->total_amount,
                'status' => GatewayPaymentStatus::Created,
                'return_url' => $returnUrl,
            ],
        ));
    }

    /**
     * A return address the demo client may name: a full http or https address with no `#` part, up to 500 characters.
     */
    private function isOpenReturnAddress(string $url): bool
    {
        return strlen($url) <= 500
            && ! str_contains($url, '#')
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
