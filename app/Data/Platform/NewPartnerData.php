<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * One partner to create with its first login: the partner's name and code prefix, and the login's name and email.
 * The code prefix is upper case and the email lower case, as the Form Request normalizes them.
 */
class NewPartnerData extends Data
{
    public function __construct(
        public string $partnerName,
        public string $codePrefix,
        public string $name,
        public string $email,
    ) {}
}
