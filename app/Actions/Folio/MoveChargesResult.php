<?php

namespace App\Actions\Folio;

use App\Models\Folio;

/**
 * What `MoveCharges` returns: the count and amount of the charges moved (the amount counts their tax and discount
 * children), the number of rooms whose scheduled charges now go to the target, the source folios, or a refusal code
 * (its own codes below, the `TransferLines` codes and the `RoutingRuleResult` codes pass through). `rooms` names the
 * rooms behind `nothing_scheduled`. See docs/13-folio/07-move-charges.md.
 */
class MoveChargesResult
{
    public const REFUSAL_NOTHING_TO_MOVE = 'nothing_to_move';

    public const REFUSAL_TOO_MANY_CHARGES = 'too_many_charges';

    public const REFUSAL_NOTHING_SCHEDULED = 'nothing_scheduled';

    /**
     * @param  list<Folio>  $sources
     * @param  list<string>  $rooms
     */
    private function __construct(
        public readonly ?string $refusal,
        public readonly int $chargeCount,
        public readonly string $amount,
        public readonly int $scheduleCount,
        public readonly array $sources,
        public readonly array $rooms,
    ) {}

    /**
     * @param  list<Folio>  $sources
     */
    public static function moved(int $chargeCount, string $amount, int $scheduleCount, array $sources): self
    {
        return new self(null, $chargeCount, $amount, $scheduleCount, $sources, []);
    }

    /**
     * @param  list<string>  $rooms
     */
    public static function refused(string $refusal, array $rooms = []): self
    {
        return new self($refusal, 0, '0.00', 0, [], $rooms);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
