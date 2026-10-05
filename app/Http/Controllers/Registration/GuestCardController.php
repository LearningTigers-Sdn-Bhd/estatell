<?php

namespace App\Http\Controllers\Registration;

use App\Actions\Registration\PrepareRegistrationCard;
use App\Actions\Registration\SaveRegistrationCardDraft;
use App\Actions\Registration\SignRegistrationCard;
use App\Actions\Registration\VoidRegistrationCard;
use App\Enums\Gender;
use App\Enums\IdentityDocumentType;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Enums\RegistrationCardStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\SaveRegistrationCardDraftRequest;
use App\Http\Requests\Registration\SignRegistrationCardRequest;
use App\Http\Requests\Registration\VoidRegistrationCardRequest;
use App\Models\Property;
use App\Models\RegistrationCard;
use App\Models\UnitGuest;
use App\Services\Guests\CountryOptions;
use App\Services\Registration\RegistrationCardPageBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestCardController extends Controller
{
    /**
     * The staff page: the card's details and the hand-over actions. Opening it starts a draft when there is none.
     */
    public function show(Request $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, RegistrationCardPageBuilder $builder, CountryOptions $countries): Response|RedirectResponse
    {
        return $this->cardPage('registration/card', $request, $property, $unitGuest, $prepareRegistrationCard, $builder, $countries);
    }

    public function update(SaveRegistrationCardDraftRequest $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, SaveRegistrationCardDraft $saveRegistrationCardDraft): RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = $prepareRegistrationCard($unitGuest);
        $saved = $card !== null && $saveRegistrationCardDraft($request->user(), $card, $request->actionInput());

        Inertia::flash('toast', $saved
            ? ['type' => 'success', 'message' => __('Card details saved.')]
            : ['type' => 'error', 'message' => __('Only an unsigned card can be changed.')]);

        return to_route('registration.show', [$property, $unitGuest]);
    }

    /**
     * The guest screen: the card to check, tick and sign, without staff actions.
     * The open language is in the URL, and switching it reloads only the card. A signed card goes to the thank-you screen.
     */
    public function guest(Request $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, RegistrationCardPageBuilder $builder, CountryOptions $countries): Response|RedirectResponse
    {
        return $this->cardPage('registration/guest', $request, $property, $unitGuest, $prepareRegistrationCard, $builder, $countries);
    }

    public function sign(SignRegistrationCardRequest $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, SignRegistrationCard $signRegistrationCard): RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = $prepareRegistrationCard($unitGuest);
        $signed = $card !== null && $signRegistrationCard($request->user(), $card, $request->actionInput());

        if ($signed) {
            return to_route('registration.done', [$property, $unitGuest]);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => __('The card could not be signed. Tick every box, sign in the box, and try again.')]);

        return to_route('registration.guest', [$property, $unitGuest, 'locale' => $request->validated('locale')]);
    }

    /**
     * The thank-you screen after signing, with the next guest of the unit who still needs a card.
     */
    public function done(Property $property, UnitGuest $unitGuest, RegistrationCardPageBuilder $builder): Response
    {
        $this->ensureOwned($property, $unitGuest);

        return Inertia::render('registration/done', ['done' => $builder->done($property, $unitGuest)]);
    }

    public function void(VoidRegistrationCardRequest $request, Property $property, UnitGuest $unitGuest, VoidRegistrationCard $voidRegistrationCard): RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = RegistrationCard::query()->where('unit_guest_id', $unitGuest->id)->where('status', '<>', 'void')->first();
        $voided = $card !== null && $voidRegistrationCard($request->user(), $card, $request->validated('reason'));

        Inertia::flash('toast', $voided
            ? ['type' => 'success', 'message' => __('Registration card voided. A new card can be signed.')]
            : ['type' => 'error', 'message' => __('There is no open card to void.')]);

        return to_route('registration.show', [$property, $unitGuest]);
    }

    private function cardPage(string $component, Request $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, RegistrationCardPageBuilder $builder, CountryOptions $countries): Response|RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = $prepareRegistrationCard($unitGuest);

        if ($card === null) {
            return $this->backToReservation($property, $unitGuest, __('This guest cannot have a registration card now.'));
        }

        if ($component === 'registration/guest' && $card->status === RegistrationCardStatus::Signed) {
            return to_route('registration.done', [$property, $unitGuest]);
        }

        $page = $builder->build($property, $card, Locale::tryFrom((string) $request->query('locale')), $request->user()->can(PermissionEnum::RegistrationCardVoid->value));

        if ($page === null) {
            return $this->backToReservation($property, $unitGuest, __('Publish the registration terms first, in Settings.'));
        }

        return Inertia::render($component, [
            'card' => $page,
            'genders' => Gender::options(),
            'identityTypes' => IdentityDocumentType::options(),
            'countries' => $countries->options(),
        ]);
    }

    private function ensureOwned(Property $property, UnitGuest $unitGuest): void
    {
        abort_unless($unitGuest->reservationUnit()->where('property_id', $property->id)->exists(), 404);
    }

    private function backToReservation(Property $property, UnitGuest $unitGuest, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('reservations.show', [$property, $unitGuest->reservationUnit->reservation_id]);
    }
}
