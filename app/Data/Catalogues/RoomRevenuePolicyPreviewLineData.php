<?php

namespace App\Data\Catalogues;

use Spatie\LaravelData\Data;

/** One case of the policy preview. A free case has no amounts. A null total means the taxes could not be calculated. */
class RoomRevenuePolicyPreviewLineData extends Data
{
    public function __construct(
        public string $label,
        public bool $free,
        public ?string $net,
        public ?string $localTotal,
        public ?string $foreignTotal,
    ) {}
}
