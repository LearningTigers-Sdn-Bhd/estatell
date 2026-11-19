<?php

namespace App\Http\Controllers\Concierge;

use App\Actions\Concierge\CheckInConciergeStay;
use App\Actions\Concierge\ConfirmStayPayment;
use App\Actions\Concierge\OpenConciergeStay;
use App\Actions\Concierge\SaveConciergeIdentity;
use App\Actions\Concierge\StartStayPayment;
use App\Actions\DoorLocks\RetryStayPasscodes;
use App\Actions\Kiosk\AnnounceWalkInBooking;
use App\Actions\Kiosk\SignStayRegistrationCard;
use App\Data\Property\PropertySummaryData;
use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concierge\ConfirmPaymentRequest;
use App\Http\Requests\Concierge\FindStayRequest;
use App\Http\Requests\Concierge\SaveIdentityRequest;
use App\Http\Requests\Concierge\SignCardRequest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeGuide;
use App\Services\Concierge\ConciergePageBuilder;
use App\Services\FrontDesk\SelfCheckInPayment;
use App\Services\Guests\CountryOptions;
use App\Services\Kiosk\KioskPin;
use App\Services\Payments\CurlecGateway;
use Brick\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Concierge page: a guest checks in on their own phone. There is no login. The lobby asks for the booking code and
 * booking code and sends the guest to the stay's own link; every other page works from that link (`EnsureConciergeIsActive`
 * resolves it). Controllers only call one Action and answer with a page or a redirect.
 */
class ConciergeController extends Controller
{
    public function lobby(Property $property, ConciergeGuide $guide): Response
    {
        $property->loadMissing('settings');

        return Inertia::render('concierge/lobby', [
            'property' => PropertySummaryData::fromModel($property, forGuest: true),
            'heroUrl' => $property->heroUrl(),
            'walkInEnabled' => (bool) $property->settings?->concierge_walk_in_enabled,
            'bookingUrl' => $guide->bookingUrl($property),
        ]);
    }

    public function find(FindStayRequest $request, Property $property, OpenConciergeStay $openStay): RedirectResponse
    {
        $result = $openStay($property, $request->string('reference')->toString(), (string) $request->ip());

        if ($result->token !== null) {
            return to_route('concierge.show', ['token' => $result->token]);
        }

        return back()->withErrors(['reference' => $result->refusal === 'rate_limited'
            ? __('Too many tries. Wait :minutes minutes and try again.', ['minutes' => max(1, (int) ceil($result->retryAfter / 60))])
            : __('We could not find a booking with that code. Check it, or use the link in your booking message.')]);
    }

    public function show(Request $request, string $token, ConciergePageBuilder $builder, CountryOptions $countries): Response
    {
        $locale = Locale::tryFrom((string) $request->query('locale'));
        $stay = $builder->build($token, $this->stay($request), $locale);

        return Inertia::render('concierge/stay', [
            'stay' => $stay,
            'identityKinds' => [['value' => 'my_kad', 'label' => __('MyKad')], ['value' => 'passport', 'label' => __('Passport')]],
            'genders' => Gender::options(),
            'countries' => $countries->optionsWithMalaysiaFirst($locale?->value),
        ]);
    }

    public function identity(SaveIdentityRequest $request, string $token, SaveConciergeIdentity $saveIdentity): RedirectResponse
    {
        $stay = $this->stay($request);
        $result = $saveIdentity($stay, $request->toIdentityData(), $request->file('scan'), $request->file('scan_back'));

        if ($result->accepted) {
            $this->announceUnpaidFree($stay);
        }

        Inertia::flash('toast', match (true) {
            ! $result->accepted => ['type' => 'error', 'message' => __('We could not save your ID. Check the number and your name, then try again.')],
            $result->nationalityRequired => ['type' => 'error', 'message' => __('Choose your nationality to continue.')],
            default => ['type' => 'success', 'message' => __('Your details are saved.')],
        });

        return to_route('concierge.show', ['token' => $token]);
    }

    /**
     * A walk-in that owes nothing up front never reaches a payment, so its booking email would wait for check-in. Send it
     * once the ID step has the guest's email. `AnnounceWalkInBooking` sends once, so a later payment or check-in adds nothing.
     */
    private function announceUnpaidFree(ReservationUnit $stay): void
    {
        if (app(SelfCheckInPayment::class)->isPaidEnough($stay)) {
            DB::transaction(fn () => app(AnnounceWalkInBooking::class)($stay->property_id, $stay->reservation_id));
        }
    }

    public function sign(SignCardRequest $request, string $token, SignStayRegistrationCard $signCard): RedirectResponse
    {
        $signed = $signCard($this->stay($request), (int) $request->input('terms_version'), $request->string('signature_png')->toString(), $request->locale());

        if (! $signed) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The card could not be signed. The terms may have changed. Read them again and sign.')]);
        }

        return to_route('concierge.show', ['token' => $token]);
    }

    public function checkIn(Request $request, string $token, CheckInConciergeStay $checkIn): RedirectResponse
    {
        $coordinates = $request->validate(['latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180']]);
        $result = $checkIn($this->stay($request), isset($coordinates['latitude']) ? (float) $coordinates['latitude'] : null, isset($coordinates['longitude']) ? (float) $coordinates['longitude'] : null);

        if ($result->refusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => match ($result->refusal) {
                'card_required_for_check_in' => __('Sign the registration card first.'),
                'deposit_not_paid' => __('A payment is still due. Pay it on this page to continue.'),
                'nationality_required' => __('Choose your nationality in your details first.'),
                'arrival_not_reached' => __('Check-in opens on your arrival day.'),
                'check_in_time_not_reached' => __('Check-in has not opened yet. Come back at check-in time.'),
                'location_required' => __('Allow location access so we can see you are at the property, then try again.'),
                'too_far_away' => __('Check-in opens when you are at the property. Try again when you arrive.'),
                'hold_expired' => __('Your room hold has expired. Ask the front desk to book the room again.'),
                default => __('No room is ready for you yet. The front desk will prepare one: ask at the desk.'),
            }]);
        }

        return to_route('concierge.show', ['token' => $token]);
    }

    /**
     * Start the Curlec order for what the stay owes and give the page what its checkout needs. JSON, because the checkout
     * opens in the page; the money is posted by `confirmPayment` and by Curlec's webhook.
     */
    public function pay(Request $request, string $token, StartStayPayment $startPayment, CurlecGateway $gateway): JsonResponse
    {
        $stay = $this->stay($request);
        $result = $startPayment($stay);

        if ($result->payment === null) {
            return response()->json(['message' => $result->message, 'code' => $result->refusal], 409);
        }

        $currency = $stay->property->settings->currency->getCurrencyCode();

        return response()->json([
            'key' => $gateway->keyId($result->payment->mode),
            'order_id' => $result->payment->order_id,
            'amount' => Money::of($result->payment->amount, $currency)->getMinorAmount()->toInt(),
            'currency' => $currency,
            'name' => $stay->property->name,
            'callback_url' => route('webhooks.curlec.stay-return', ['token' => $token]),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Where Curlec sends the guest back from a bank (FPX) redirect, as a POST from another site, so it has no session or
     * CSRF token. The signature is the proof, and a failed or cancelled payment simply lands the guest on the page again.
     */
    public function paymentReturn(Request $request, string $token, ConfirmStayPayment $confirmPayment): RedirectResponse
    {
        $stay = $this->stay($request);

        $confirmPayment(
            $stay,
            $request->string('razorpay_order_id')->toString(),
            $request->string('razorpay_payment_id')->toString(),
            $request->string('razorpay_signature')->toString(),
        );

        return redirect()->route($stay->refresh()->status === UnitStatus::CheckedIn ? 'concierge.bill' : 'concierge.show', ['token' => $token], 303);
    }

    /**
     * "Try again" on the key step when the door code could not be made. Three tries per stay in ten minutes, so a lock that
     * is down is not hammered; a stay with no lock or TTLock off cannot be fixed by asking, so nothing is queued.
     */
    public function retryPin(Request $request, string $token, KioskPin $pin, RetryStayPasscodes $retry): RedirectResponse
    {
        $stay = $this->stay($request);
        $limit = 'pin-retry-limit:'.$stay->id;

        if ($pin->canRetry($stay)) {
            if (RateLimiter::tooManyAttempts($limit, 3)) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('Please wait a few minutes before trying again, or ask the front desk.')]);
            } else {
                RateLimiter::hit($limit, 600);
                $retry($stay);
            }
        }

        return to_route('concierge.show', ['token' => $token]);
    }

    public function confirmPayment(ConfirmPaymentRequest $request, string $token, ConfirmStayPayment $confirmPayment): RedirectResponse
    {
        $confirmed = $confirmPayment(
            $this->stay($request),
            $request->string('razorpay_order_id')->toString(),
            $request->string('razorpay_payment_id')->toString(),
            $request->string('razorpay_signature')->toString(),
        );

        if (! $confirmed) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We could not confirm your payment yet. If money left your account it will be added to your booking in a few minutes. Refresh this page, or ask the front desk.')]);
        }

        return to_route($this->stay($request)->status === UnitStatus::CheckedIn ? 'concierge.bill' : 'concierge.show', ['token' => $token]);
    }

    private function stay(Request $request): ReservationUnit
    {
        /** @var ReservationUnit */
        return $request->attributes->get('concierge.stay');
    }
}
