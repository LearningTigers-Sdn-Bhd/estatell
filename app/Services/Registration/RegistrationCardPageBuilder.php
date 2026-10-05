<?php

namespace App\Services\Registration;

use App\Data\Registration\RegistrationCardPageData;
use App\Enums\Locale;
use App\Models\Property;
use App\Models\RegistrationCard;

/**
 * Collects what the staff card page needs and hands it to `RegistrationCardPageData::forCard`. It writes nothing.
 */
class RegistrationCardPageBuilder
{
    public function __construct(
        private RegistrationTermsResolver $termsResolver,
        private TermsRenderer $termsRenderer,
        private RegistrationCardStay $stay,
        private RegistrationCardLabels $labels,
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

        return RegistrationCardPageData::forCard(
            $card,
            $guest,
            $unit,
            $unitGuest->id,
            $property->registrationCardFields()->ordered()->get(),
            $resolved,
            $this->termsRenderer->render($resolved->terms->body, $this->termsRenderer->valuesFor($property)),
            $this->stay->valuesFor($unitGuest, $property),
            $this->labels->for($resolved->locale),
            $guest->documents()->orderByDesc('id')->get(),
            $canVoid,
        );
    }
}
