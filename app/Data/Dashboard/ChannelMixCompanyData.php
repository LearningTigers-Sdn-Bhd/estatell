<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * A company inside the Company / Travel agent row of the channel mix.
 */
class ChannelMixCompanyData extends Data
{
    public function __construct(
        public string $name,
        public int $nights,
    ) {}
}
