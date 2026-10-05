<?php

namespace App\Data\Registration;

use App\Enums\Locale;
use App\Enums\TermsPreset;
use App\Models\RegistrationCardTerms;
use App\Models\TermsDocument;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/** Everything the Terms tab editor shows for one document in one language. */
class TermsEditorData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $localeOptions
     * @param  array<string, string>  $placeholderValues  keyed by placeholder name, for the preview
     * @param  Collection<int, TermsVersionData>  $history  newest first
     */
    public function __construct(
        public int $documentId,
        public string $locale,
        public array $localeOptions,
        public ?int $version,
        public string $body,
        public bool $isDraft,
        public ?string $publishedAt,
        public ?string $publishedBy,
        public ?string $presetLabel,
        public ?string $newerTemplateBody,
        public array $placeholderValues,
        #[DataCollectionOf(TermsVersionData::class)]
        public Collection $history,
    ) {}

    /**
     * @param  Collection<int, RegistrationCardTerms>  $versions  every version of the document in this language, newest first
     * @param  array<string, string>  $placeholderValues
     * @param  list<Locale>  $publishedLocales  languages the document has a version in
     */
    public static function forDocument(TermsDocument $document, Locale $locale, Collection $versions, array $publishedLocales, TermsPreset $preset, array $placeholderValues): self
    {
        $latest = $versions->first();
        $newerTemplate = $latest?->preset !== null && $latest->preset_version < $latest->preset->version($locale);

        return new self(
            documentId: $document->id,
            locale: $locale->value,
            localeOptions: array_map(
                fn (Locale $option): array => [
                    'value' => $option->value,
                    'label' => in_array($option, $publishedLocales, true) ? $option->label() : __(':language (not published)', ['language' => $option->label()]),
                ],
                Locale::cases(),
            ),
            version: $latest?->version,
            body: $latest?->body ?? $preset->body($locale),
            isDraft: $latest === null,
            publishedAt: $latest?->published_at->toIso8601String(),
            publishedBy: $latest?->publisher?->name,
            presetLabel: $latest?->preset?->label() ?? ($latest === null ? $preset->label() : null),
            newerTemplateBody: $newerTemplate ? $latest->preset->body($locale) : null,
            placeholderValues: $placeholderValues,
            history: $versions->map(fn (RegistrationCardTerms $terms): TermsVersionData => TermsVersionData::fromModel($terms))->values(),
        );
    }
}
