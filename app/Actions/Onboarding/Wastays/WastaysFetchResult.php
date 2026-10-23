<?php

namespace App\Actions\Onboarding\Wastays;

use App\Services\Onboarding\Wastays\WastaysBundle;

final readonly class WastaysFetchResult
{
    /** @param  list<string>  $problems */
    public function __construct(
        public ?WastaysBundle $bundle,
        public array $problems = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->bundle !== null;
    }
}
