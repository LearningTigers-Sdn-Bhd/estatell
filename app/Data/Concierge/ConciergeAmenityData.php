<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

class ConciergeAmenityData extends Data
{
    public function __construct(
        public string $name,
        public string $category,
    ) {}
}
