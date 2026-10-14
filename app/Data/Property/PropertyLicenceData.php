<?php

namespace App\Data\Property;

use App\Models\PropertyLicence;
use Spatie\LaravelData\Data;

/** One row of the licences table. */
class PropertyLicenceData extends Data
{
    public function __construct(
        public int $id,
        public string $issuer,
        public string $number,
        public ?string $expiresOn,
        public bool $showOnDocuments,
    ) {}

    public static function fromModel(PropertyLicence $licence): self
    {
        return new self(
            id: $licence->id,
            issuer: $licence->issuer,
            number: $licence->number,
            expiresOn: $licence->expires_on?->toDateString(),
            showOnDocuments: $licence->show_on_documents,
        );
    }
}
