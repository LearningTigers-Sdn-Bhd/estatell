<?php

namespace App\Actions\Registration;

use App\Enums\Locale;
use App\Models\RegistrationCardTerms;
use App\Models\TermsDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PublishRegistrationTerms
{
    /**
     * Publish a text as the next version of a document in one language.
     *
     * Returns null, without writing, when the text is the same as the latest version.
     * A version is never edited afterwards, because signed cards point to it.
     */
    public function __invoke(TermsDocument $document, Locale $locale, User $actor, string $body): ?RegistrationCardTerms
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        return DB::transaction(function () use ($document, $locale, $actor, $body): ?RegistrationCardTerms {
            $document = TermsDocument::query()->lockForUpdate()->findOrFail($document->id);
            $latest = $document->versions()->forLocale($locale)->orderByDesc('version')->first();

            if ($latest !== null && trim(str_replace("\r\n", "\n", $latest->body)) === $body) {
                return null;
            }

            $terms = $document->versions()->create([
                'locale' => $locale,
                'version' => ($latest->version ?? 0) + 1,
                'body' => $body,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            activity()
                ->performedOn($document)
                ->causedBy($actor)
                ->event('registration_terms.published')
                ->withProperties(['property_id' => $document->property_id, 'locale' => $locale->value, 'version' => $terms->version])
                ->log('Registration terms published');

            return $terms;
        });
    }
}
