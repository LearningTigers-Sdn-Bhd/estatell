<?php

namespace App\Actions\Registration;

use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Enums\TermsPreset;
use App\Models\Property;
use App\Models\RegistrationCardTerms;
use App\Models\TermsDocument;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use Illuminate\Support\Facades\DB;

class ApplyTermsPreset
{
    /**
     * Copy a template into a document as its next version in one language.
     *
     * Without a preset, the one for the property type is used. Returns null, without writing,
     * when the latest version already has exactly this text.
     */
    public function __invoke(TermsDocument $document, Locale $locale, ?User $actor, ?TermsPreset $preset = null): ?RegistrationCardTerms
    {
        return DB::transaction(function () use ($document, $locale, $actor, $preset): ?RegistrationCardTerms {
            $document = TermsDocument::query()->lockForUpdate()->findOrFail($document->id);
            $property = Property::query()->findOrFail($document->property_id);
            $preset ??= TermsPreset::forPropertyType($property->type ?? PropertyType::Hotel);
            $body = $preset->body($locale, CountryProfiles::for((string) $property->country_code)->usesMalaysianTermsItems());

            $latest = $document->versions()->forLocale($locale)->orderByDesc('version')->first();

            if ($latest !== null && $this->same($latest->body, $body)) {
                return null;
            }

            $terms = $document->versions()->create([
                'locale' => $locale,
                'version' => ($latest->version ?? 0) + 1,
                'body' => $body,
                'preset' => $preset,
                'preset_version' => $preset->version($locale),
                'published_at' => now(),
                'published_by' => $actor?->id,
            ]);

            if ($actor !== null) {
                activity()
                    ->performedOn($document)
                    ->causedBy($actor)
                    ->event('registration_terms.template_applied')
                    ->withProperties(['property_id' => $property->id, 'locale' => $locale->value, 'version' => $terms->version, 'preset' => $preset->value])
                    ->log('Registration terms template applied');
            }

            return $terms;
        });
    }

    private function same(string $a, string $b): bool
    {
        return trim(str_replace("\r\n", "\n", $a)) === trim(str_replace("\r\n", "\n", $b));
    }
}
