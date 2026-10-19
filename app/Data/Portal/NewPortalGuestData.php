<?php

namespace App\Data\Portal;

use App\Enums\IdentityDocumentType;
use Spatie\LaravelData\Data;

/**
 * The lead guest of one unit of a portal booking, in unit order: each room, then each bed. Always saved as a new
 * guest record. The ID is optional; it needs both the type and the number.
 */
class NewPortalGuestData extends Data
{
    public function __construct(
        public ?string $fullName,
        public ?string $phone = null,
        public ?IdentityDocumentType $idType = null,
        public ?string $idNumber = null,
    ) {}
}
