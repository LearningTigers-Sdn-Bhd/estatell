<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * One POS receipt to post. `amount` is the room-charge tender in gross, a positive decimal string with at most two
 * decimals. `failureHint` is set by an adapter that could not match a room, so the receipt waits for review.
 */
class PosChargeData extends Data
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $externalReceiptId,
        public string $externalReceiptNumber,
        public string $amount,
        public ?int $reservationUnitId,
        public array $rawPayload,
        public ?string $serviceDate = null,
        public ?string $failureHint = null,
    ) {}
}
