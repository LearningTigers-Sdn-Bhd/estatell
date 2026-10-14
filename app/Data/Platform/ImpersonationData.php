<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * The shared `impersonation` prop behind the support banner.
 */
class ImpersonationData extends Data
{
    public function __construct(
        public string $supportUserName,
        public ?string $partnerName,
        public string $userName,
        public ?string $userRole,
        public string $propertyName,
        public string $propertyCode,
        public int $minutesLeft,
        public string $stopUrl,
        public bool $canCorrectMoney,
    ) {}
}
