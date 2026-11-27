<?php

namespace App\Data\Reservations;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * A staff member who can be named as the sales person of a booking. See docs/12-reservations/12-sales-person.md.
 */
class SalesPersonOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self($user->id, $user->name);
    }
}
