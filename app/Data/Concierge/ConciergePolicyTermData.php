<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * What the property tells a guest about one charge policy, such as cancellation, in the words the owner wrote.
 */
class ConciergePolicyTermData extends Data
{
    public function __construct(
        public string $label,
        public string $text,
    ) {}
}
