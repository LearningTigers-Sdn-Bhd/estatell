<?php

namespace App\Services\Registration;

use App\Enums\Locale;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\TermsDocument;

/**
 * Finds the terms a guest signs: the right document, in the right language, at its latest version.
 */
class RegistrationTermsResolver
{
    /**
     * The document is the room type's, else the property default. A room type document with no published
     * version is skipped. The language is the requested one, else the guest's preferred one, else the
     * property default, each only if the document has it, else the first one it has.
     *
     * Returns null when the property has no document with a published version.
     */
    public function resolve(Property $property, ?RoomType $roomType = null, ?Guest $guest = null, ?Locale $requested = null): ?ResolvedTerms
    {
        foreach ($this->candidateDocuments($property, $roomType) as $document) {
            $locales = $this->localesOf($document);

            if ($locales === []) {
                continue;
            }

            $locale = $this->startingLocale($locales, $requested, $guest?->preferred_locale, $property->settings?->default_locale);
            $terms = $document->versions()->forLocale($locale)->orderByDesc('version')->firstOrFail();

            return new ResolvedTerms($document, $locale, $terms, $locales);
        }

        return null;
    }

    /**
     * @return list<TermsDocument>
     */
    private function candidateDocuments(Property $property, ?RoomType $roomType): array
    {
        $documents = [];

        if ($roomType?->terms_document_id !== null) {
            $own = $property->termsDocuments()->find($roomType->terms_document_id);

            if ($own !== null) {
                $documents[] = $own;
            }
        }

        $default = $property->termsDocuments()->where('is_default', true)->first();

        if ($default !== null) {
            $documents[] = $default;
        }

        return $documents;
    }

    /**
     * @return list<Locale>
     */
    private function localesOf(TermsDocument $document): array
    {
        $published = $document->versions()->distinct()->pluck('locale')
            ->map(fn (mixed $value): Locale => $value instanceof Locale ? $value : Locale::from($value))
            ->all();

        return array_values(array_filter(Locale::cases(), fn (Locale $locale): bool => in_array($locale, $published, true)));
    }

    /**
     * @param  non-empty-list<Locale>  $available
     */
    private function startingLocale(array $available, ?Locale ...$preferences): Locale
    {
        foreach ($preferences as $preference) {
            if ($preference !== null && in_array($preference, $available, true)) {
                return $preference;
            }
        }

        return $available[0];
    }
}
