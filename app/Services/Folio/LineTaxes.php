<?php

namespace App\Services\Folio;

use App\Actions\Folio\PostingResult;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Services\Reservations\UnitNationality;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Reads the charge and tax lines that the manual split and tax Actions work on. It writes nothing.
 * See docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
 */
class LineTaxes
{
    public function __construct(
        private LineSourceUnits $sourceUnits,
        private UnitNationality $unitNationality,
    ) {}

    /**
     * The refusal code that stops a manual action on this line, or null when the line is a live top-level charge on
     * an Open folio with no live discount. The checks run in the order of the codes: payment, reversal, child,
     * reversed, not a charge, folio, discount.
     */
    public function chargeRefusal(FolioLine $line, Folio $folio): ?string
    {
        return match (true) {
            $line->payment()->exists() => PostingResult::REFUSAL_PAYMENT_LINE,
            $line->reversal_of_id !== null => PostingResult::REFUSAL_LINE_IS_REVERSAL,
            $line->parent_line_id !== null => PostingResult::REFUSAL_LINE_IS_CHILD,
            $line->reversal()->exists() => PostingResult::REFUSAL_ALREADY_REVERSED,
            $line->kind !== TransactionKind::Charge => PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE,
            $folio->status !== FolioStatus::Open => PostingResult::REFUSAL_FOLIO_NOT_OPEN,
            $line->children()->where('kind', TransactionKind::Adjustment)->whereDoesntHave('reversal')->exists() => PostingResult::REFUSAL_LINE_HAS_DISCOUNT,
            default => null,
        };
    }

    /**
     * The tax lines of the charge that are still live, oldest first.
     *
     * @return Collection<int, FolioLine>
     */
    public function liveTaxLines(FolioLine $charge): Collection
    {
        return $charge->children()->where('kind', TransactionKind::Tax)->whereDoesntHave('reversal')->orderBy('id')->get();
    }

    /**
     * The property tax a tax line was calculated from, found by its transaction code.
     */
    public function taxOf(FolioLine $taxLine, int $propertyId): ?Tax
    {
        return Tax::query()->where('property_id', $propertyId)->where('transaction_code_id', $taxLine->transaction_code_id)->first();
    }

    /**
     * The nationality that decides a foreign-only tax for the unit of the line, or null when the line has no unit.
     */
    public function nationality(FolioLine $line): ?string
    {
        $unitId = $this->sourceUnits->for(collect([$line]))[$line->source_type.':'.$line->source_id] ?? null;
        $unit = $unitId === null ? null : ReservationUnit::query()->find($unitId);

        return $unit === null ? null : $this->unitNationality->for($unit);
    }

    /**
     * What was typed when the line was posted: the unit amount times the quantity (gross when a tax is inclusive).
     */
    public function enteredAmount(FolioLine $charge, string $currency): Money
    {
        return Money::of($charge->unit_amount, $currency)->multipliedBy($charge->quantity);
    }
}
