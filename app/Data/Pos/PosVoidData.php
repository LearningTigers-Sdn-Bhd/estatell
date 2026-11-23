<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * A void or refund of an earlier receipt. `amount` is null for a full void; a smaller amount is a partial refund.
 */
class PosVoidData extends Data
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $externalReceiptId,
        public string $originalExternalReceiptId,
        public ?string $amount = null,
        public ?string $reason = null,
        public array $rawPayload = [],
        public ?int $posConnectionId = null,
    ) {}
}
