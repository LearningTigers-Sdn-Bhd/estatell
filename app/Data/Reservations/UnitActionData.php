<?php

namespace App\Data\Reservations;

use App\Enums\UnitAction;
use Spatie\LaravelData\Data;

/**
 * One entry of the unit ⋯ menu. The server sends only what the unit's status and the user's permissions allow.
 */
class UnitActionData extends Data
{
    public function __construct(
        public UnitAction $key,
        public string $label,
    ) {}

    public static function fromAction(UnitAction $action): self
    {
        return new self($action, $action->label());
    }
}
