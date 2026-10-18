<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * One demo property and the email of its Owner login.
 */
class DemoLoginData extends Data
{
    public function __construct(
        public string $property,
        public string $email,
    ) {}
}
