<?php

namespace App\Data\Property;

use Spatie\LaravelData\Data;

/**
 * The letterhead line switches. Each one shows or hides a line of the printed header.
 */
class PrintHeaderData extends Data
{
    public function __construct(
        public bool $showLogo,
        public bool $showAddress,
        public bool $showContact,
        public bool $showRegistrationNumber,
        public bool $showTin,
    ) {}
}
