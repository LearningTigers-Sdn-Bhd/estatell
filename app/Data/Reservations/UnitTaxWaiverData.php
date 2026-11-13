<?php

namespace App\Data\Reservations;

use App\Enums\TaxWaiverReason;
use App\Models\ReservationUnitTaxWaiver;
use Spatie\LaravelData\Data;

/**
 * One tax waived for the unit, as the unit panel shows it: "Paid by Trip.com · 1128000012345678" or "Paid by guest
 * · receipt TTX-88231". Expects `tax` to be loaded. See docs/06-catalogues/03-taxes.md#tax-waivers.
 */
class UnitTaxWaiverData extends Data
{
    public function __construct(
        public int $taxId,
        public string $taxName,
        public string $summary,
    ) {}

    public static function fromModel(ReservationUnitTaxWaiver $waiver): self
    {
        return new self($waiver->tax_id, $waiver->tax->name, self::summary($waiver));
    }

    /**
     * Who paid the tax and the reference, in the words the panel, the folio and the documents use.
     */
    public static function summary(ReservationUnitTaxWaiver $waiver): string
    {
        return $waiver->reason === TaxWaiverReason::PaidElsewhere
            ? __('Paid by :payer · :reference', ['payer' => $waiver->paid_to, 'reference' => $waiver->reference])
            : __('Paid by guest · receipt :reference', ['reference' => $waiver->reference]);
    }
}
