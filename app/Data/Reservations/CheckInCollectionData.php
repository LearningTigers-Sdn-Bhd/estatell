<?php

namespace App\Data\Reservations;

use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * What Collect now offers at check-in: one row for each tax flagged **Collect at check-in** (a foreign-only tax the
 * guest does not pay shows as exempt with 0; a waived tax shows with 0 and `waivedBy`; `waivable` is true when staff can
 * mark it as paid elsewhere), the security deposit, and whether the taxes are not collected because the
 * room charges route to a Master folio (`taxesHidden`: the rows then carry `chargedTo` and are information only). `billTotal` is what is still owed on the bill of the unit (every Scheduled row, less `billPaid`), which Collect now
 * takes as one Advance when Room charges and tax is ticked. `billPaid` is what the Unit folio already holds towards it. `billHidden` is true when the reservation has a company and
 * any billing mode but Each unit pays: the company is billed, so the bill is never collected. All amounts are read only
 * text, the server works them out again on submit. `charges` are the Scheduled charges that go to the unit's own
 * folio or elsewhere: a Room entry and one per other transaction code, each saying where it is charged and whether
 * the desk may take it (`chargesPaid` is what the unit folio already holds; `masterOwed` and `masterPaid` are what the
 * Master still owes and holds, null and 0 without one), so the check-in Dialog can take the ones the guest pays now.
 */
class CheckInCollectionData extends Data
{
    /**
     * @param  list<array{codeId: int, name: string, count: int, perNight: bool, label: string, amount: string, isStay: bool, holdsTaxes: bool, folio: string, chargedTo: string|null, payable: bool}>  $charges
     * @param  list<array{taxId: int, name: string, amount: string, exempt: bool, waivedBy: string|null, chargedTo: string|null, payableOnMaster: bool, waivable: bool}>  $taxes
     */
    public function __construct(
        public array $taxes,
        public string $taxesTotal,
        public bool $taxesHidden,
        public ?string $deposit,
        public string $billTotal = '0.00',
        public bool $billHidden = false,
        public string $billPaid = '0.00',
        public array $charges = [],
        public string $chargesPaid = '0.00',
        public ?string $masterOwed = null,
        public string $masterPaid = '0.00',
    ) {}

    /**
     * What the Full bill still asks for: the unit-folio charges less `chargesPaid`, plus the payable Master charges up
     * to what the Master still owes (`masterOwed`).
     */
    public function chargesOwed(): string
    {
        return (string) $this->unitPart(null)->plus($this->masterPart(null))->toScale(2);
    }

    /**
     * What Collect now takes on the unit folio for the ticked charges, never more than that folio still owes.
     *
     * @param  list<int>  $codeIds
     */
    public function chargesFor(array $codeIds): string
    {
        return (string) $this->unitPart($codeIds)->toScale(2);
    }

    /**
     * What Collect now takes on the Master for the ticked charges (a Master no company pays), never more than the
     * Master still owes.
     *
     * @param  list<int>  $codeIds
     */
    public function masterChargesFor(array $codeIds): string
    {
        return (string) $this->masterPart($codeIds)->toScale(2);
    }

    /**
     * True when a ticked charge is the room and holds the collected taxes, so the tax rows are not taken again.
     *
     * @param  list<int>  $codeIds
     */
    public function includesStay(array $codeIds): bool
    {
        return array_any($this->charges, fn (array $charge): bool => $charge['holdsTaxes'] && $charge['payable'] && in_array($charge['codeId'], $codeIds, true));
    }

    /**
     * @param  list<int>|null  $codeIds  Null for every charge.
     */
    private function unitPart(?array $codeIds): BigDecimal
    {
        $all = $this->sum('unit', null);

        return BigDecimal::min($this->sum('unit', $codeIds), BigDecimal::max(BigDecimal::zero(), $all->minus($this->chargesPaid)));
    }

    /**
     * @param  list<int>|null  $codeIds  Null for every charge.
     */
    private function masterPart(?array $codeIds): BigDecimal
    {
        return $this->masterOwed === null ? BigDecimal::zero() : BigDecimal::min($this->sum('master', $codeIds), BigDecimal::of($this->masterOwed));
    }

    /**
     * @param  list<int>|null  $codeIds
     */
    private function sum(string $folio, ?array $codeIds): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->charges as $charge) {
            if ($charge['payable'] && $charge['folio'] === $folio && ($codeIds === null || in_array($charge['codeId'], $codeIds, true))) {
                $total = $total->plus($charge['amount']);
            }
        }

        return $total;
    }

    /**
     * What Collect now takes for the taxes: the rows the guest pays at check-in (not on the Master folio, not waived,
     * not exempt), only the chosen tax ids when `$taxIds` is a list. Null takes every one.
     *
     * @param  list<int>|null  $taxIds
     */
    public function taxesFor(?array $taxIds): string
    {
        if ($this->taxesHidden) {
            return '0.00';
        }

        $total = BigDecimal::zero();

        foreach ($this->taxes as $tax) {
            if ($tax['waivedBy'] === null && ! $tax['exempt'] && ($taxIds === null || in_array($tax['taxId'], $taxIds, true))) {
                $total = $total->plus($tax['amount']);
            }
        }

        return (string) $total->toScale(2);
    }

    /**
     * What Collect now takes for the taxes the guest pays into the Master folio: the ticked `payableOnMaster` rows
     * (`CollectNowData::$masterTaxIds`). A caller that sends none (walk-in, kiosk) takes nothing here.
     *
     * @param  list<int>  $masterTaxIds
     */
    public function masterTaxesFor(array $masterTaxIds): string
    {
        $total = BigDecimal::zero();

        foreach ($this->taxes as $tax) {
            if ($tax['payableOnMaster'] && in_array($tax['taxId'], $masterTaxIds, true)) {
                $total = $total->plus($tax['amount']);
            }
        }

        return (string) $total->toScale(2);
    }

    public function isEmpty(): bool
    {
        return $this->taxes === [] && $this->deposit === null && ($this->billHidden || ! BigDecimal::of($this->billTotal)->isPositive())
            && ! BigDecimal::of($this->chargesOwed())->isPositive();
    }
}
