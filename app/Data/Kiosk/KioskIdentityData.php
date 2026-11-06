<?php

namespace App\Data\Kiosk;

use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\Gender;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The ID read by a kiosk, already validated. `nationalityCode` is an ISO code or null when the kiosk sent one Estatell does not know.
 * The optional fields are null when the kiosk left them out or sent a value that did not parse.
 */
class KioskIdentityData extends Data
{
    public function __construct(
        public IdentityDocumentInputData $document,
        public string $fullName,
        public ?string $nationalityCode,
        public ?CarbonImmutable $dateOfBirth,
        public ?Gender $gender,
    ) {}
}
