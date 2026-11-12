<?php

namespace App\Services\EInvoice;

use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;

/**
 * The folio lines an e-invoice reports, read from the folio's loaded lines: charges, adjustments and taxes that are
 * not reversals and were not reversed. A reversal is always posted on the folio of the line it reverses (`ReverseLine`)
 * and a reversed line cannot be transferred (`TransferLines`), so the folio's own lines are enough. Payments are never
 * reported: a closed folio's payments bring its balance to 0. Loads `lines.transactionCode` when missing. It reads only.
 */
class ReportableLines
{
    /**
     * Charge and adjustment lines, in posting order.
     *
     * @return Collection<int, FolioLine>
     */
    public function charges(Folio $folio): Collection
    {
        return $this->standing($folio, [TransactionKind::Charge, TransactionKind::Adjustment]);
    }

    /**
     * Tax lines, in posting order.
     *
     * @return Collection<int, FolioLine>
     */
    public function taxes(Folio $folio): Collection
    {
        return $this->standing($folio, [TransactionKind::Tax]);
    }

    /**
     * What the stay cost the guest: its charges, adjustments and taxes, to the sen.
     */
    public function gross(Folio $folio): BigDecimal
    {
        return $this->sum($this->charges($folio))->plus($this->sum($this->taxes($folio)));
    }

    /**
     * The stay as a monthly summary reports it: its charges before tax (`net`) and its taxes (`tax`); by LHDN tax
     * type, the tax, the charges it is on (`taxable`) and its rate when it is a percentage (`percent`); and the
     * charges with no tax on them (`untaxed`).
     *
     * @return array{net: BigDecimal, tax: BigDecimal, taxes: array<string, BigDecimal>, taxable: array<string, BigDecimal>, percent: array<string, BigDecimal|null>, untaxed: BigDecimal}
     */
    public function split(Folio $folio, LhdnTaxType $taxTypes): array
    {
        $charges = $this->charges($folio);
        $taxes = [];
        $percent = [];
        $chargeIds = [];

        foreach ($this->taxes($folio) as $line) {
            $type = $taxTypes->for($line);
            $taxes[$type] = ($taxes[$type] ?? BigDecimal::zero())->plus($line->amount);
            $percent[$type] ??= $taxTypes->percent($line);
            $chargeIds[$type][] = $line->parent_line_id;
        }

        // A tax not tied to a charge is on all the charges, so none of them is untaxed.
        $allTaxed = collect($chargeIds)->flatten()->contains(null);
        $taxed = $allTaxed ? $charges->pluck('id') : collect($chargeIds)->flatten()->filter()->unique();
        $taxable = [];
        foreach ($chargeIds as $type => $ids) {
            $on = $charges->whereIn('id', array_filter($ids));
            // A tax not tied to a charge is taken on all the charges.
            $taxable[$type] = $on->isEmpty() ? $this->sum($charges) : $this->sum($on);
        }

        return [
            'net' => $this->sum($charges),
            'tax' => $this->sum($this->taxes($folio)),
            'taxes' => $taxes,
            'taxable' => $taxable,
            'percent' => $percent,
            'untaxed' => $taxes === [] ? $this->sum($charges) : $this->sum($charges->whereNotIn('id', $taxed)),
        ];
    }

    /**
     * @param  iterable<FolioLine>  $lines
     */
    public function sum(iterable $lines): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($lines as $line) {
            $sum = $sum->plus($line->amount);
        }

        return $sum->toScale(2);
    }

    /**
     * @param  list<TransactionKind>  $kinds
     * @return Collection<int, FolioLine>
     */
    private function standing(Folio $folio, array $kinds): Collection
    {
        $lines = $folio->loadMissing('lines.transactionCode')->lines;
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();

        return $lines
            ->filter(fn (FolioLine $line): bool => in_array($line->kind, $kinds, true) && $line->reversal_of_id === null && ! $reversed->has($line->id))
            ->values();
    }
}
