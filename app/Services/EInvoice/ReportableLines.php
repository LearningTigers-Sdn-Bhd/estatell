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
     * The stay's charges before tax, its taxes, and its taxes by LHDN tax type, as a monthly summary reports them.
     *
     * @return array{net: BigDecimal, tax: BigDecimal, taxes: array<string, BigDecimal>}
     */
    public function split(Folio $folio, LhdnTaxType $taxTypes): array
    {
        $taxes = [];
        foreach ($this->taxes($folio) as $line) {
            $type = $taxTypes->for($line);
            $taxes[$type] = ($taxes[$type] ?? BigDecimal::zero())->plus($line->amount);
        }

        return [
            'net' => $this->sum($this->charges($folio)),
            'tax' => $this->sum($this->taxes($folio)),
            'taxes' => $taxes,
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
