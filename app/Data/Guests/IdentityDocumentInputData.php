<?php

namespace App\Data\Guests;

use App\Enums\IdentityDocumentType;
use Spatie\LaravelData\Data;

/**
 * An ID document typed in a form: the fields to save on a guest's document. The `number` is already normalized.
 * The scans are not part of it; they are uploaded files passed to the Actions beside it.
 */
class IdentityDocumentInputData extends Data
{
    public function __construct(
        public IdentityDocumentType $type,
        public string $number,
        public ?string $issuingCountryCode,
        public ?string $expiresOn,
    ) {}
}
