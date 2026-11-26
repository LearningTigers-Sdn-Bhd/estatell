<?php

namespace App\Http\Controllers\Concierge;

use App\Actions\Concierge\ConciergeWalkInBooked;
use App\Actions\Concierge\ConfirmConciergeQuote;
use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Data\Concierge\ConciergeQuoteData;
use App\Data\Kiosk\KioskWalkInData;
use App\Data\Property\PropertySummaryData;
use App\Enums\Gender;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concierge\BookQuoteRequest;
use App\Http\Requests\Concierge\ReturningQuoteRequest;
use App\Models\ConciergeQuote;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Guests\CountryOptions;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Kiosk\KioskWalkInOffer;
use App\Services\Registration\RegistrationCardFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A quote link the AI assistant sent: the guest sees the room, plan and dates priced again, gives their details, and only
 * then is the room booked and held, the same way as a Concierge walk-in. A used link opens the stay page instead.
 * See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class ConciergeQuoteController extends Controller
{
    public function show(Request $request, Property $property, string $quote, KioskWalkIn $walkIn, RegistrationCardFieldRules $fieldRules, CountryOptions $countries, IssueBookingPreCheckInToken $issueToken): Response|RedirectResponse
    {
        $conciergeQuote = $this->quote($property, $quote);

        if ($conciergeQuote->reservation_id !== null && $conciergeQuote->expires_at->isFuture()) {
            return $this->openStay($conciergeQuote, $issueToken);
        }

        $search = new KioskWalkInData($conciergeQuote->arrival_date, (int) $conciergeQuote->arrival_date->diffInDays($conciergeQuote->departure_date), $conciergeQuote->adults);
        $offer = $conciergeQuote->reservation_id === null && $conciergeQuote->expires_at->isFuture()
            ? array_find($walkIn->quotable($property, $search, $conciergeQuote->room_type_id, $conciergeQuote->rate_plan_id), fn (KioskWalkInOffer $offer): bool => $offer->available)
            : null;
        $locale = Locale::tryFrom((string) $request->query('locale'));

        return Inertia::render('concierge/quote', [
            'property' => PropertySummaryData::fromModel($property, forGuest: true),
            'quote' => ConciergeQuoteData::fromQuote($conciergeQuote, $offer, $property->settings->currency),
            'nationalityRequired' => $fieldRules->nationalityIsNeededForTax($property),
            'idPhotoRequired' => (bool) $property->settings?->concierge_name_check_in_enabled,
            'identityKinds' => [['value' => 'my_kad', 'label' => __('MyKad')], ['value' => 'passport', 'label' => __('Passport')]],
            'genders' => Gender::options(),
            'countries' => $countries->optionsWithMalaysiaFirst($locale?->value),
        ]);
    }

    public function book(BookQuoteRequest $request, Property $property, string $quote, ConfirmConciergeQuote $confirm, IssueBookingPreCheckInToken $issueToken): RedirectResponse
    {
        $conciergeQuote = $this->quote($property, $quote);
        $result = $confirm->withIdentity($conciergeQuote, $request->toIdentityData(), $request->file('scan'), $request->file('scan_back'));

        return match ($result->refusal) {
            null => $this->booked($result, $issueToken, __('Your details are saved.')),
            'nationality_required' => back()->withErrors(['nationality' => __('Choose your nationality to continue.')]),
            'identity_refused' => back()->withErrors(['number' => __('We could not save your ID. Check the number and your name, then try again.')]),
            default => $this->refused($property, $conciergeQuote->token, $result->refusal),
        };
    }

    public function returning(ReturningQuoteRequest $request, Property $property, string $quote, ConfirmConciergeQuote $confirm, IssueBookingPreCheckInToken $issueToken): RedirectResponse
    {
        $conciergeQuote = $this->quote($property, $quote);
        $result = $confirm->returning($conciergeQuote, $request->string('contact')->toString());

        return match ($result->refusal) {
            null => $this->booked($result, $issueToken, __('Welcome back. We have your details.')),
            'no_match' => back(),
            default => $this->refused($property, $conciergeQuote->token, $result->refusal),
        };
    }

    /** The route's `{quote}` is the quote token. It is not named `token`: the `concierge` middleware reads that as a stay link. */
    private function quote(Property $property, string $token): ConciergeQuote
    {
        $property->loadMissing('settings');

        return ConciergeQuote::query()->where('property_id', $property->id)->where('token', $token)->with(['roomType', 'ratePlan'])->firstOrFail();
    }

    private function booked(ConciergeWalkInBooked $result, IssueBookingPreCheckInToken $issueToken, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('concierge.show', ['token' => basename($issueToken($result->stay))]);
    }

    private function openStay(ConciergeQuote $quote, IssueBookingPreCheckInToken $issueToken): RedirectResponse
    {
        $stay = ReservationUnit::query()->where('property_id', $quote->property_id)->where('reservation_id', $quote->reservation_id)->with('property.settings')->firstOrFail();

        return to_route('concierge.show', ['token' => basename($issueToken($stay))]);
    }

    /**
     * Back to the quote page, which shows the state again (used, expired or no longer free), with the reason.
     */
    private function refused(Property $property, string $token, string $refusal): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => match ($refusal) {
            'busy' => __('Many rooms are being booked right now. Try again in a few minutes, or ask the front desk.'),
            'quote_expired' => __('This quote has expired. Ask us again in the chat.'),
            'quote_used' => __('This quote is already booked.'),
            default => __('That room is no longer available. Ask us again in the chat for another room.'),
        }]);

        return to_route('concierge.quote', ['property' => $property->code, 'quote' => $token]);
    }
}
