<?php

namespace App\Data\Onboarding;

use Spatie\LaravelData\Data;

class GoLiveCheckData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $done,
    ) {}
}
