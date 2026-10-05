<?php

namespace App\Services\Registration;

use App\Enums\Locale;
use App\Models\RegistrationCardTerms;
use App\Models\TermsDocument;

/**
 * The terms a card shows: the document, the version in the chosen language, and every language on offer.
 */
final readonly class ResolvedTerms
{
    /**
     * @param  list<Locale>  $locales  languages the document has a published version in
     */
    public function __construct(
        public TermsDocument $document,
        public Locale $locale,
        public RegistrationCardTerms $terms,
        public array $locales,
    ) {}
}
