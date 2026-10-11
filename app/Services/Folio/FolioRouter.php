<?php

namespace App\Services\Folio;

use App\Enums\FolioType;
use App\Enums\RoutingRuleSource;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use Closure;
use Illuminate\Support\Collection;

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

    /**
     * Whether a Room revenue charge of the unit goes to a Master folio, by the real rules (BillingMode and Manual).
     * `RecordPayment` refuses an Advance on such a unit, and `CheckInCollection` hides the tax rows. A property with
     * no Room revenue code is false.
     */
    public function roomChargesGoToMaster(ReservationUnit $unit): bool
    {
        $roomCode = TransactionCode::query()
            ->where('property_id', $unit->property_id)
            ->where('system_key', SystemTransactionCode::RoomRevenue->value)
            ->first();

        return $roomCode !== null && $this->targetFor($unit, $roomCode)?->type === FolioType::Master;
    }

    /**
     * The same choice as `targetFor()` for many postings of one reservation, with the rules and the unit folios read
     * once. The closure returns the id of the target folio, or null while the unit has no folio. It writes nothing.
     * The folio page uses it to place Scheduled rows. `$proposedRules` are unsaved rules added to the real ones for a
     * preview (Move charges): a proposed rule replaces a saved Manual rule with the same selectors, as the Action will.
     *
     * @param  list<FolioRoutingRule>  $proposedRules
     * @return Closure(ReservationUnit, TransactionCode): ?int
     */
    public function resolverFor(Reservation $reservation, array $proposedRules = []): Closure
    {
        $rules = $this->rulesWith($reservation, $proposedRules);
        $unitFolioIds = Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('type', FolioType::Unit)
            ->oldest('id')
            ->pluck('id', 'reservation_unit_id')
            ->all();

        return fn (ReservationUnit $unit, TransactionCode $code): ?int => $this->pick($rules, $unit, $code)?->target_folio_id
            ?? ($unitFolioIds[$unit->id] ?? null);
    }

    /**
     * The rule that wins for a unit and a code, or null when none matches (the unit's own folio then applies). The
     * Move charges preview names it in its notes. Same inputs as `resolverFor()`.
     *
     * @param  list<FolioRoutingRule>  $proposedRules
     * @return Closure(ReservationUnit, TransactionCode): ?FolioRoutingRule
     */
    public function pickerFor(Reservation $reservation, array $proposedRules = []): Closure
    {
        $rules = $this->rulesWith($reservation, $proposedRules);

        return fn (ReservationUnit $unit, TransactionCode $code): ?FolioRoutingRule => $this->pick($rules, $unit, $code);
    }

    /**
     * The saved rules, minus the Manual rules that a proposed rule replaces, plus the proposed rules.
     *
     * @param  list<FolioRoutingRule>  $proposedRules
     * @return Collection<int, FolioRoutingRule>
     */
    private function rulesWith(Reservation $reservation, array $proposedRules): Collection
    {
        $rules = $reservation->routingRules()->get();

        if ($proposedRules === []) {
            return $rules;
        }

        $same = fn (FolioRoutingRule $a, FolioRoutingRule $b): bool => $a->reservation_unit_id === $b->reservation_unit_id
            && $a->category === $b->category
            && $a->transaction_code_id === $b->transaction_code_id;

        return $rules
            ->reject(fn (FolioRoutingRule $rule): bool => $rule->source === RoutingRuleSource::Manual
                && collect($proposedRules)->contains(fn (FolioRoutingRule $proposed): bool => $same($rule, $proposed)))
            ->concat($proposedRules)
            ->values();
    }

    /**
     * @param  Collection<int, FolioRoutingRule>  $rules
     */
    private function pick(Collection $rules, ReservationUnit $unit, TransactionCode $code): ?FolioRoutingRule
    {
        return $rules
            ->filter(fn (FolioRoutingRule $rule): bool => ($rule->reservation_unit_id === null || $rule->reservation_unit_id === $unit->id)
                && ($rule->transaction_code_id === null || $rule->transaction_code_id === $code->id)
                && ($rule->category === null || $rule->category === $code->category))
            ->sortByDesc(fn (FolioRoutingRule $rule): int => $this->specificity($rule))
            ->first();
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
