<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * What the front desk or an earlier stay already holds about the guest, so the ID step starts filled and a returning
 * guest only checks it. Sent on the ID step alone. Every value may be empty. The date of birth and the document number are never sent: only
 * whether a birth date is saved and the last characters of the number, so the page can say "saved" and the server keeps them.
 */
class ConciergeIdentityPrefillData extends Data
{
    public function __construct(
        public ?string $fullName,
        public ?string $nationalityCode,
        public bool $hasDateOfBirth,
        public ?string $gender,
        public ?string $documentKind,
        public ?string $documentNumberHint,
    ) {}
}
