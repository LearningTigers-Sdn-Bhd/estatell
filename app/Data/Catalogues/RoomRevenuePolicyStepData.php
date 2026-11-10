<?php

namespace App\Data\Catalogues;

use App\Models\RoomRevenuePolicyStep;
use Spatie\LaravelData\Data;

/** One cancellation charge step as the form edits it: the percent or the amount of the policy's charge mode. */
class RoomRevenuePolicyStepData extends Data
{
    public function __construct(
        public int $daysBefore,
        public ?string $amount,
        public ?string $percent,
    ) {}

    public static function fromModel(RoomRevenuePolicyStep $step): self
    {
        return new self($step->days_before, $step->amount, $step->percent);
    }
}
