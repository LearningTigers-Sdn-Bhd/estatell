<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * What the front desk or an earlier stay already holds about the guest, so the ID step starts filled and a returning
 * guest only checks it. Sent on the ID step alone. Every value may be empty. The date of birth and the document number are never sent: only
 * whether a birth date is saved and the last characters of the number, so the page can say "saved" and the server keeps them.
 * A walk-in joined to a returning guest by an email or phone gets no values at all (`hideKnown`): only which of the name, phone
 * and email the profile already holds, so the page asks for the ID alone and nothing else it already has.
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
        public bool $hideKnown = false,
        public bool $hasName = false,
        public bool $hasPhone = false,
        public bool $hasEmail = false,
        public bool $hasDocumentPhoto = false,
    ) {}
}
