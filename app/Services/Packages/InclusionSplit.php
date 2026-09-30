<?php

namespace App\Services\Packages;

use Brick\Money\Money;

/**
 * A night's amount split into the room and its inclusion lines, or the reason it cannot be split.
 */
class InclusionSplit
{
    public const REFUSAL_GUESTS_REQUIRED = 'guests_required';

    public const REFUSAL_EXCEEDS_NIGHT_AMOUNT = 'exceeds_night_amount';

    /**
     * @param  list<InclusionLine>  $lines
     */
    private function __construct(
        public readonly ?Money $room,
        public readonly array $lines,
        public readonly ?string $refusal,
    ) {}

    /**
     * @param  list<InclusionLine>  $lines
     */
    public static function split(Money $room, array $lines): self
    {
        return new self($room, $lines, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, [], $reason);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
