<?php

namespace App\Actions\Kiosk;

use App\Actions\Registration\PrepareRegistrationCard;
use App\Actions\Registration\SignRegistrationCard;
use App\Data\Registration\SignRegistrationCardData;
use App\Enums\Locale;
use App\Enums\RegistrationCardStatus;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskRegistrationTerms;
use App\Services\Kiosk\KioskStays;
use App\Services\Registration\TermsRenderer;

class SignStayRegistrationCard
{
    public function __construct(
        private KioskStays $stays,
        private KioskRegistrationTerms $terms,
        private TermsRenderer $renderer,
        private PrepareRegistrationCard $prepareCard,
        private SignRegistrationCard $signCard,
    ) {}

    /**
     * Sign the primary guest's registration card with the signature from the kiosk. The guest has read the terms on
     * the kiosk screen and ticked every checklist item there, so all items count as ticked. The terms must still be the
     * version the guest saw. The signature is a PNG, as a data URL or plain base64; type and size are checked by
     * `SignRegistrationCard`. The guest's details stay as the kiosk saved them; the signer is the guest's name.
     *
     * A card that is already signed answers true, so a retry is safe. False, without writing, for a stay without a
     * primary guest, no published terms, another terms version, a bad signature or a closed card or guest.
     */
    public function __invoke(ReservationUnit $stay, int $termsVersion, string $signaturePng, ?Locale $requested): bool
    {
        $unitGuest = $this->stays->primaryUnitGuest($stay);
        $guest = $this->stays->guest($stay);
        $card = $unitGuest === null || $guest === null ? null : ($this->prepareCard)($unitGuest);

        if ($card === null) {
            return false;
        }

        if ($card->status === RegistrationCardStatus::Signed) {
            return true;
        }

        $resolved = $this->terms->resolve($stay, $requested);

        if ($resolved === null || $resolved->terms->version !== $termsVersion || trim($guest->full_name) === '') {
            return false;
        }

        $document = $guest->documents()->orderByDesc('id')->first();

        return ($this->signCard)(null, $card, new SignRegistrationCardData(
            profile: [],
            identity: null,
            identityDocumentId: $document?->id,
            vehiclePlate: null,
            remarks: null,
            marketingConsent: false,
            locale: $resolved->locale,
            ticks: array_keys($this->renderer->items($resolved->terms->body)),
            signature: str_starts_with($signaturePng, 'data:') ? $signaturePng : 'data:image/png;base64,'.$signaturePng,
            signerName: $guest->full_name,
        ));
    }
}
