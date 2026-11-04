<?php

namespace App\Services\Kiosk;

use App\Enums\Locale;
use App\Models\ReservationUnit;
use App\Services\Registration\RegistrationTermsResolver;
use App\Services\Registration\ResolvedTerms;
use App\Services\Registration\TermsRenderer;

/**
 * The registration card terms a kiosk guest reads: the terms the card would use for this stay (the same resolver as the
 * staff and guest screens), with the property placeholders filled. Reads only.
 */
class KioskRegistrationTerms
{
    public function __construct(private RegistrationTermsResolver $resolver, private TermsRenderer $renderer, private KioskStays $stays) {}

    /**
     * Null when the property has no published terms.
     */
    public function resolve(ReservationUnit $stay, ?Locale $requested): ?ResolvedTerms
    {
        $stay->loadMissing(['property.settings', 'roomType']);

        return $this->resolver->resolve($stay->property, $stay->roomType, $this->stays->guest($stay), $requested);
    }

    public function text(ReservationUnit $stay, ResolvedTerms $resolved): string
    {
        return $this->renderer->render($resolved->terms->body, $this->renderer->valuesFor($stay->property))['text'];
    }
}
