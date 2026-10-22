<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The damage charge of the check-out form: the amount staff typed (before tax) and an optional note. It posts on the
 * property's damage charge item, on the unit's own folio, with the key `{uuid}:damage`, before the payment and the
 * refund. See docs/18-front-desk/01-check-in-and-check-out.md#keep-the-deposit-for-damages.
 */
class CheckOutDamageData extends Data
{
    public function __construct(
        public string $amount,
        public ?string $note,
    ) {}
}
