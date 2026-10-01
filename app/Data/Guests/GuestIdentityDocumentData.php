<?php

namespace App\Data\Guests;

use App\Models\GuestIdentityDocument;
use App\Services\Guests\CountryOptions;
use Spatie\LaravelData\Data;

/**
 * An ID document for the Documents tab and its form. The full `number` is sent only to the edit form;
 * lists carry the masked number. Expects the `media` relation to be loaded.
 */
class GuestIdentityDocumentData extends Data
{
    public function __construct(
        public int $id,
        public string $type,
        public string $typeLabel,
        public string $maskedNumber,
        public ?string $number,
        public ?string $issuingCountryCode,
        public ?string $issuingCountryName,
        public ?string $expiresOn,
        public bool $isExpired,
        public bool $hasFrontScan,
        public bool $hasBackScan,
    ) {}

    /**
     * @param  string  $today  The property's date, as `Y-m-d`.
     */
    public static function fromModel(GuestIdentityDocument $document, string $today, bool $withNumber = false): self
    {
        $expiresOn = $document->expires_on?->toDateString();

        return new self(
            id: $document->id,
            type: $document->type->value,
            typeLabel: $document->type->label(),
            maskedNumber: $document->maskedNumber(),
            number: $withNumber ? $document->formattedNumber() : null,
            issuingCountryCode: $document->issuing_country_code,
            issuingCountryName: app(CountryOptions::class)->name($document->issuing_country_code),
            expiresOn: $expiresOn,
            isExpired: $expiresOn !== null && $expiresOn < $today,
            hasFrontScan: $document->hasMedia(GuestIdentityDocument::FRONT_SCAN),
            hasBackScan: $document->hasMedia(GuestIdentityDocument::BACK_SCAN),
        );
    }
}
