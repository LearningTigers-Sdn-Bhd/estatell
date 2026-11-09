<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * The login fields an admin saves on one OTA list row. A null password keeps the saved one.
 */
class OtaCredentialLoginData extends Data
{
    public function __construct(
        public string $propertyCode,
        public string $username,
        public ?string $password,
        public ?string $marketManagerName,
        public ?string $marketManagerPhone,
        public ?string $marketManagerEmail,
    ) {}
}
