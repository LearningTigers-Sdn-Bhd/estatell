<?php

namespace App\Services\Folio;

use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;

/**
 * Chooses the folio a charge lands on. The most specific matching rule of the reservation wins: a rule with the
 * code, then a rule with the category, then a rule for all categories. At the same level a rule for the posting unit
 * beats a rule for all units. With no rule the unit's own folio is chosen, or null before the unit has one
 * (`PostCharge` then creates it). It follows one rule and stops: no chains. See docs/13-folio/03-routing.md.
 */
class FolioRouter
{
    public function targetFor(ReservationUnit $unit, TransactionCode $code): ?Folio
    {
        $rule = FolioRoutingRule::query()
            ->where('reservation_id', $unit->reservation_id)
            ->where(fn ($query) => $query->whereNull('reservation_unit_id')->orWhere('reservation_unit_id', $unit->id))
            ->where(fn ($query) => $query->whereNull('transaction_code_id')->orWhere('transaction_code_id', $code->id))
            ->where(fn ($query) => $query->whereNull('category')->orWhere('category', $code->category->value))
            ->with('targetFolio')
            ->get()
            ->sortByDesc(fn (FolioRoutingRule $rule): int => $this->specificity($rule))
            ->first();

        return $rule?->targetFolio ?? $this->unitFolio($unit);
    }

    private function unitFolio(ReservationUnit $unit): ?Folio
    {
        return Folio::query()
            ->where('property_id', $unit->property_id)
            ->where('reservation_unit_id', $unit->id)
            ->where('type', FolioType::Unit)
            ->oldest('id')
            ->first();
    }

    /**
     * 4 for a code rule, 2 for a category rule, 0 for a rule for all. A rule for the posting unit adds 1.
     */
    private function specificity(FolioRoutingRule $rule): int
    {
        $level = match (true) {
            $rule->transaction_code_id !== null => 4,
            $rule->category !== null => 2,
            default => 0,
        };

        return $level + ($rule->reservation_unit_id === null ? 0 : 1);
    }
}
