<?php

namespace App\Actions\Reservations;

use App\Models\UnitAddOn;

/**
 * What `AddUnitAddOn` returns: the created add-on rows, or a refusal code. `AddUnit` passes the code through
 * `ReservationResult`.
 */
class AddOnResult
{
    public const REFUSAL_CHARGE_ITEM_NOT_FOUND = 'charge_item_not_found';

    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_SERVICE_DATE_OUTSIDE_STAY = 'service_date_outside_stay';

    public const REFUSAL_SERVICE_DATE_IN_PAST = 'service_date_in_past';

    public const REFUSAL_QUANTITY_INVALID = 'quantity_invalid';

    public const REFUSAL_AMOUNT_INVALID = 'amount_invalid';

    public const REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED = 'amount_override_not_allowed';

    /**
     * @param  list<UnitAddOn>  $addOns
     */
    private function __construct(
        public readonly array $addOns,
        public readonly ?string $refusal,
    ) {}

    /**
     * @param  list<UnitAddOn>  $addOns
     */
    public static function created(array $addOns): self
    {
        return new self($addOns, null);
    }

    public static function refused(string $refusal): self
    {
        return new self([], $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
