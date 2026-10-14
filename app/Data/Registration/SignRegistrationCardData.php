<?php

namespace App\Data\Registration;

use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\Locale;
use Spatie\LaravelData\Data;

/**
 * The guest's signing input: the profile fields to save, the ID document, the ticked checklist positions and the
 * signature. `signature` is a PNG data URL. `ticks` are the zero-based positions of the ticked items.
 */
class SignRegistrationCardData extends Data
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $profile,
        public ?IdentityDocumentInputData $identity,
        public ?int $identityDocumentId,
        public ?string $vehiclePlate,
        public ?string $remarks,
        public bool $marketingConsent,
        public Locale $locale,
        /** @var list<int> */
        public array $ticks,
        public string $signature,
        public string $signerName,
    ) {}
}
