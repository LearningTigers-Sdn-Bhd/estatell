<?php

namespace App\Data\Registration;

use App\Enums\Locale;
use App\Models\TermsDocument;
use Spatie\LaravelData\Data;

/** One terms document: a row of the Terms list, and the header of its editor page. */
class TermsDocumentData extends Data
{
    /**
     * @param  list<array{locale: string, label: string, version: int|null}>  $languages  the latest version per language, null while unpublished
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
        public bool $canDelete,
        public int $roomTypeCount,
        public array $languages,
        public ?string $lastPublishedAt,
    ) {}

    /**
     * Needs `withCount('roomTypes')` and the `versions` relation loaded (locale, version and published_at are enough).
     */
    public static function fromModel(TermsDocument $document): self
    {
        $versions = $document->versions;

        return new self(
            id: $document->id,
            name: $document->name,
            isDefault: $document->is_default,
            canDelete: ! $document->is_default && $document->room_types_count === 0,
            roomTypeCount: $document->room_types_count,
            languages: array_map(
                fn (Locale $locale): array => [
                    'locale' => $locale->value,
                    'label' => $locale->label(),
                    'version' => $versions->where('locale', $locale)->max('version'),
                ],
                Locale::cases(),
            ),
            lastPublishedAt: $versions->max('published_at')?->toIso8601String(),
        );
    }
}
