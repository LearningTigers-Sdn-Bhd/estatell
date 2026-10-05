<?php

namespace App\Http\Controllers\Registration;

use App\Actions\Registration\PrepareRegistrationCard;
use App\Actions\Registration\SignRegistrationCard;
use App\Actions\Registration\VoidRegistrationCard;
use App\Enums\Gender;
use App\Enums\IdentityDocumentType;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
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
     * The registration card of one guest on one unit. Opening it starts a draft when there is none.
     * The open language is in the URL, and switching it reloads only the card.
     */
    public function show(Request $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, RegistrationCardPageBuilder $builder, CountryOptions $countries): Response|RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = $prepareRegistrationCard($unitGuest);

        if ($card === null) {
            return $this->backToReservation($property, $unitGuest, __('This guest cannot have a registration card now.'));
        }

        $page = $builder->build($property, $card, Locale::tryFrom((string) $request->query('locale')), $request->user()->can(PermissionEnum::RegistrationCardVoid->value));

        if ($page === null) {
            return $this->backToReservation($property, $unitGuest, __('Publish the registration terms first, in Settings.'));
        }

        return Inertia::render('registration/card', [
            'card' => $page,
            'genders' => Gender::options(),
            'identityTypes' => IdentityDocumentType::options(),
            'countries' => $countries->options(),
        ]);
    }

    public function sign(SignRegistrationCardRequest $request, Property $property, UnitGuest $unitGuest, PrepareRegistrationCard $prepareRegistrationCard, SignRegistrationCard $signRegistrationCard): RedirectResponse
    {
        $this->ensureOwned($property, $unitGuest);

        $card = $prepareRegistrationCard($unitGuest);
        $signed = $card !== null && $signRegistrationCard($request->user(), $card, $request->actionInput());

        Inertia::flash('toast', $signed
            ? ['type' => 'success', 'message' => __('Registration card signed.')]
            : ['type' => 'error', 'message' => __('The card could not be signed. Tick every box, sign in the box, and try again.')]);

        return to_route('registration.show', [$property, $unitGuest]);
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
