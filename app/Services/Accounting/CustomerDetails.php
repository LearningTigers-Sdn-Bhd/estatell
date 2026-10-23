<?php

namespace App\Services\Accounting;

/**
 * A company as a customer to create in the accounting software.
 */
final readonly class CustomerDetails
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $postalCode,
        public ?string $countryCode,
        public bool $international,
    ) {}
}
