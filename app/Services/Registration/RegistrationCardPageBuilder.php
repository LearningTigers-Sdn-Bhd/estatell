<?php

namespace App\Services\Registration;

use App\Data\Registration\RegistrationCardDoneData;
use App\Data\Registration\RegistrationCardPageData;
use App\Enums\Locale;
use App\Enums\RegistrationCardStatus;
use App\Models\Property;
use App\Models\RegistrationCard;
use App\Models\UnitGuest;

/**
 * Collects what the staff card page, the guest screen and the thank-you screen need. It writes nothing.
 */
class RegistrationCardPageBuilder
{
    public function __construct(
        private RegistrationTermsResolver $termsResolver,
        private TermsRenderer $termsRenderer,
        private RegistrationCardStay $stay,
        private RegistrationCardLabels $labels,
        private RegistrationCardRequirement $requirement,
        private RegistrationCardSnapshot $snapshot,
    ) {}

    /**
     * Returns null when the property has no published terms. A signed card shows the terms it was signed with.
     */
    public function build(Property $property, RegistrationCard $card, ?Locale $requested, bool $canVoid): ?RegistrationCardPageData
    {
        $unitGuest = $card->unitGuest()->with(['guest', 'reservationUnit.roomType'])->firstOrFail();
        $unit = $unitGuest->reservationUnit;
        $guest = $unitGuest->guest;
        $resolved = $card->terms === null
            ? $this->termsResolver->resolve($property, $unit->roomType, $guest, $requested)
            : new ResolvedTerms($card->terms->document, $card->terms->locale, $card->terms, [$card->terms->locale]);

        if ($resolved === null) {
            return null;
        }

        $stay = $this->stay->valuesFor($unitGuest, $property);
        $required = $this->requirement->requiredFor($unit, $property);
        $position = $required->search(fn (UnitGuest $required): bool => $required->id === $unitGuest->id);

        return RegistrationCardPageData::forCard(
            $card,
            $guest,
            $unit,
            $unitGuest->id,
            $property->registrationCardFields()->ordered()->get(),
            $resolved,
            $this->termsRenderer->render($resolved->terms->body, $this->termsRenderer->valuesFor($property)),
            $stay,
            $this->labels->for($resolved->locale),
            $guest->documents()->orderByDesc('id')->get(),
            $canVoid,
            $card->status === RegistrationCardStatus::Draft ? $this->snapshot->fields($property, $card, $guest, $card->identityDocument, $stay) : null,
            $position === false ? null : $position + 1,
            $required->count(),
        );
    }

    /**
     * The thank-you screen: the next guest of the same unit who still needs a card, in the language the guest signed in.
     */
    public function done(Property $property, UnitGuest $unitGuest): RegistrationCardDoneData
    {
        $unit = $unitGuest->reservationUnit()->firstOrFail();
        $signed = RegistrationCard::query()
            ->where('unit_guest_id', $unitGuest->id)
            ->where('status', RegistrationCardStatus::Signed->value)
            ->with('terms')
            ->first();
        $locale = $signed?->terms->locale ?? Locale::English;
        $next = $this->requirement->missingFor($unit, $property)->first(fn (UnitGuest $missing): bool => $missing->id !== $unitGuest->id);

        return RegistrationCardDoneData::forWalk($unitGuest, $next, $this->requirement->requiredFor($unit, $property), $locale->value, $this->labels->for($locale));
    }
}
