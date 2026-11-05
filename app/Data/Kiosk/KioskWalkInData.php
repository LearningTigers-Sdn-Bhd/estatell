<?php

namespace App\Data\Kiosk;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class KioskWalkInData extends Data
{
    /** @param list<int> $childAges */
    public function __construct(public CarbonImmutable $arrival, public int $nights, public int $adults, public array $childAges = []) {}
}
