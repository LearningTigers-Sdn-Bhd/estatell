<?php

namespace App\Services\Folio;

/**
 * The lines of one unit night, or the reason the night cannot be quoted. `charges` starts with the room, then the
 * inclusions. A charge can carry a refused tax result (a missing nationality, for example): the quote still holds
 * it so the folio page can show the night, and `taxRefusal()` names the reason for the posting.
 */
class NightQuote
{
    public const REFUSAL_DISCOUNT_EXCEEDS_ROOM = 'discount_exceeds_room';

    /**
     * @param  list<QuotedCharge>  $charges
     */
    private function __construct(
        public readonly array $charges,
        public readonly ?string $refusal,
    ) {}

    /**
     * @param  non-empty-list<QuotedCharge>  $charges
     */
    public static function calculated(array $charges): self
    {
        return new self($charges, null);
    }

    public static function refused(string $refusal): self
    {
        return new self([], $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    public function taxRefusal(): ?string
    {
        foreach ($this->charges as $charge) {
            if (! $charge->tax->isCalculated()) {
                return $charge->tax->refusalReason;
            }
        }

        return null;
    }
}
