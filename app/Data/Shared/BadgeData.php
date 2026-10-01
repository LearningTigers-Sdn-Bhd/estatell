<?php

namespace App\Data\Shared;

use App\Enums\PropertyStatus;
use App\Enums\RatePlanStatus;
use App\Enums\Tone;
use Spatie\LaravelData\Data;

/**
 * An enum value with its label and tone, for `StatusBadge`.
 */
class BadgeData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public Tone $tone,
    ) {}

    public static function fromStatus(PropertyStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromRatePlanStatus(RatePlanStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }
}
