<?php

namespace App\Data\Registration;

use App\Models\RegistrationCardTerms;
use Spatie\LaravelData\Data;

/** One published version in the version history of the Terms tab. */
class TermsVersionData extends Data
{
    public function __construct(
        public int $version,
        public string $body,
        public string $publishedAt,
        public ?string $publishedBy,
        public bool $isTemplate,
    ) {}

    public static function fromModel(RegistrationCardTerms $terms): self
    {
        return new self(
            version: $terms->version,
            body: $terms->body,
            publishedAt: $terms->published_at->toIso8601String(),
            publishedBy: $terms->publisher?->name,
            isTemplate: $terms->preset !== null,
        );
    }
}
