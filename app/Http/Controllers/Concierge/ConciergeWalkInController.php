<?php

namespace App\Http\Controllers\Concierge;

use App\Actions\Concierge\ConciergeWalkInBooked;
use App\Actions\Concierge\ConfirmConciergeWalkIn;
use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Data\Concierge\ConciergeOfferData;
use App\Data\Property\PropertySummaryData;
use App\Enums\Gender;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concierge\BookWalkInRequest;
use App\Http\Requests\Concierge\ReturningWalkInRequest;
use App\Http\Requests\Concierge\WalkInDetailsRequest;
use App\Http\Requests\Concierge\WalkInRequest;
use App\Models\Property;
use App\Services\Concierge\ConciergeDayUse;
use App\Services\Concierge\ConciergeGuide;
use App\Services\Guests\CountryOptions;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Kiosk\KioskWalkInOffer;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Registration\RegistrationCardFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A guest with no booking, on the Concierge page: search for a room, give their details, and only then is the booking made. Nothing
 * is booked while the guest looks at rooms or types their details; `ConfirmConciergeWalkIn` books the room and saves the details in
 * one step, so a booking never exists without the guest's name. See docs/30-concierge/01-overview.md#walk-in-bookings.
 */
class ConciergeWalkInController extends Controller
{
    /**
     * A guest with no booking: two adults for one night is shown first, with the server's price for each room that is free.
     */
    public function search(WalkInRequest $request, Property $property, KioskWalkIn $walkIn, CurrentBusinessDate $businessDate, ConciergeDayUse $dayUse, ConciergeGuide $guide): Response
    {
        abort_unless($property->settings?->concierge_walk_in_enabled, 404);

        $search = $request->search($property, $businessDate);
        $offers = $search === null ? [] : array_filter($walkIn->available($property, $search), fn (KioskWalkInOffer $offer): bool => $offer->available);

        return Inertia::render('concierge/walk-in', [
            'property' => PropertySummaryData::fromModel($property, forGuest: true),
            'adults' => $request->integer('adults'),
            'nights' => $request->integer('nights'),
            'stayType' => $request->isDayUse() ? 'day_use' : 'overnight',
            'startTime' => $request->isDayUse() ? $request->string('start_time')->toString() : '10:00',
            'hours' => $request->isDayUse() ? $request->integer('hours') : 4,
            'canDayUse' => $dayUse->isSold($property),
            'bookingUrl' => $guide->bookingUrl($property),
            'offers' => array_values(array_map(ConciergeOfferData::fromOffer(...), $offers)),
        ]);
    }

    /**
     * The page where a walk-in gives their details for the room they picked. Nothing is booked yet. The room is checked again, as it may
     * have gone since the search; a guest whose room has gone is sent back to the search.
     */
    public function details(WalkInDetailsRequest $request, Property $property, KioskWalkIn $walkIn, CurrentBusinessDate $businessDate, RegistrationCardFieldRules $fieldRules, CountryOptions $countries): Response|RedirectResponse
    {
        abort_unless($property->settings?->concierge_walk_in_enabled, 404);

        $search = $request->search($property, $businessDate);
        $offer = $search === null ? null : array_find($walkIn->available($property, $search, $request->integer('room_type_id')), fn (KioskWalkInOffer $offer): bool => $offer->available);

        if ($offer === null) {
            return $this->backToSearch($request, $property, 'no_room_available');
        }

        $locale = Locale::tryFrom((string) $request->query('locale'));

        return Inertia::render('concierge/walk-in-details', [
            'property' => PropertySummaryData::fromModel($property, forGuest: true),
            'offer' => ConciergeOfferData::fromOffer($offer),
            'booking' => $this->booking($request),
            'nationalityRequired' => $fieldRules->nationalityIsNeededForTax($property),
            'idPhotoRequired' => (bool) $property->settings->concierge_name_check_in_enabled,
            'identityKinds' => [['value' => 'my_kad', 'label' => __('MyKad')], ['value' => 'passport', 'label' => __('Passport')]],
            'genders' => Gender::options(),
            'countries' => $countries->optionsWithMalaysiaFirst($locale?->value),
        ]);
    }

    /**
     * The guest has given their details: book and hold the room and save the details together, then open the stay on its payment step.
     */
    public function book(BookWalkInRequest $request, Property $property, ConfirmConciergeWalkIn $confirm, IssueBookingPreCheckInToken $issueToken, CurrentBusinessDate $businessDate): RedirectResponse
    {
        abort_unless($property->settings?->concierge_walk_in_enabled, 404);

        $search = $request->search($property, $businessDate);
        $result = $search === null
            ? ConciergeWalkInBooked::refused('no_room_available')
            : $confirm->withIdentity($property, $search, $request->integer('room_type_id'), $request->toIdentityData(), $request->file('scan'), $request->file('scan_back'));

        return match ($result->refusal) {
            null => $this->openStay($result, $issueToken, __('Your details are saved.')),
            'nationality_required' => back()->withErrors(['nationality' => __('Choose your nationality to continue.')]),
            'identity_refused' => back()->withErrors(['number' => __('We could not save your ID. Check the number and your name, then try again.')]),
            default => $this->backToSearch($request, $property, $result->refusal),
        };
    }

    /**
     * A walk-in gives the email or phone number to be recognised as a returning guest. A match books the room for that guest and opens the
     * stay; no match books nothing and the page shows the full details form.
     */
    public function returning(ReturningWalkInRequest $request, Property $property, ConfirmConciergeWalkIn $confirm, IssueBookingPreCheckInToken $issueToken, CurrentBusinessDate $businessDate): RedirectResponse
    {
        abort_unless($property->settings?->concierge_walk_in_enabled, 404);

        $search = $request->search($property, $businessDate);
        $result = $search === null
            ? ConciergeWalkInBooked::refused('no_room_available')
            : $confirm->returning($property, $search, $request->integer('room_type_id'), $request->string('contact')->toString());

        return match ($result->refusal) {
            null => $this->openStay($result, $issueToken, __('Welcome back. We have your details.')),
            'no_match' => back(),
            default => $this->backToSearch($request, $property, $result->refusal),
        };
    }

    private function openStay(ConciergeWalkInBooked $result, IssueBookingPreCheckInToken $issueToken, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('concierge.show', ['token' => basename($issueToken($result->stay))]);
    }

    /**
     * The room choice as it came in, for the form to send back with the details.
     *
     * @return array{adults: int, stay_type: string, nights: int|null, start_time: string|null, hours: int|null, room_type_id: int}
     */
    private function booking(Request $request): array
    {
        $dayUse = $request->input('stay_type') === 'day_use';

        return [
            'adults' => $request->integer('adults'),
            'stay_type' => $dayUse ? 'day_use' : 'overnight',
            'nights' => $dayUse ? null : $request->integer('nights'),
            'start_time' => $dayUse ? $request->string('start_time')->toString() : null,
            'hours' => $dayUse ? $request->integer('hours') : null,
            'room_type_id' => $request->integer('room_type_id'),
        ];
    }

    /**
     * Back to the room search with the same choice and the reason the room could not be booked.
     */
    private function backToSearch(Request $request, Property $property, string $refusal): RedirectResponse
    {
        $search = array_filter($this->booking($request), fn (mixed $value, string $key): bool => $value !== null && $key !== 'room_type_id', ARRAY_FILTER_USE_BOTH);

        return to_route('concierge.walk-in', ['property' => $property->code, ...$search])->withErrors(['room_type_id' => match ($refusal) {
            'busy' => __('Many rooms are being booked right now. Try again in a few minutes, or ask the front desk.'),
            default => __('That room is no longer available. Pick another room or change the number of nights.'),
        }]);
    }
}
