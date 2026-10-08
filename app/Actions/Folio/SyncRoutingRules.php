<?php

namespace App\Actions\Folio;

use App\Enums\BillingMode;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Reservation;
use App\Services\Folio\UnitAdvancesHeld;
use Illuminate\Support\Facades\DB;

class SyncRoutingRules
{
    public function __construct(
        private CreateFolio $createFolio,
        private CreateRoutingRule $createRoutingRule,
        private UnitAdvancesHeld $unitAdvancesHeld,
    ) {}

    /**
     * Make the BillingMode rules of a reservation match its `billing_mode`. MasterPays sends Room charges to the Master
     * folio, MasterPaysAll sends all charges, EachPays sends none. Rules are created only with 2 or more live units
     * (not Cancelled or NoShow) or a `company_id`. Once the Master folio and its BillingMode rules exist, a unit that
     * later cancels never removes them: only EachPays or a stale rule does. The reservation row is locked first. The Master folio is created once, when the rules need it, or
     * when the reservation has a company (the AR invoice is made from it). Only BillingMode rules are removed or
     * changed: Manual rules are never touched, and a Manual rule on the same selectors keeps its place. Folios are
     * never deleted. Running it again changes nothing. When it creates a rule, the result names the units whose own folio
     * keeps posted room lines or Advance money. See docs/13-folio/03-routing.md.
     */
    public function __invoke(Reservation $reservation): RoutingSyncResult
    {
        return DB::transaction(function () use ($reservation): RoutingSyncResult {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            $liveUnits = $locked->units()->whereNotIn('status', [UnitStatus::Cancelled, UnitStatus::NoShow])->count();
            $grouped = $locked->company_id !== null || $liveUnits >= 2;
            $routes = $locked->billing_mode !== BillingMode::EachPays;

            $master = $this->activeMaster($locked);
            $created = false;

            if ($master === null && ($locked->company_id !== null || ($routes && $grouped))) {
                $master = ($this->createFolio)($locked, null);
                $created = true;
            }

            $established = $master !== null && FolioRoutingRule::query()
                ->where('reservation_id', $locked->id)
                ->where('source', RoutingRuleSource::BillingMode)
                ->whereNull('reservation_unit_id')
                ->whereNull('transaction_code_id')
                ->where('target_folio_id', $master->id)
                ->exists();

            $needsRule = $routes && $master !== null && ($grouped || $established);
            $category = $locked->billing_mode === BillingMode::MasterPays ? TransactionCategory::Room : null;
            $removed = 0;
            $added = 0;

            $existing = FolioRoutingRule::query()
                ->where('reservation_id', $locked->id)
                ->where('source', RoutingRuleSource::BillingMode)
                ->get();

            foreach ($existing as $rule) {
                $matches = $needsRule
                    && $rule->reservation_unit_id === null
                    && $rule->transaction_code_id === null
                    && $rule->category === $category
                    && $rule->target_folio_id === $master?->id;

                if (! $matches) {
                    $rule->delete();
                    $removed++;
                }
            }

            if ($needsRule) {
                $alreadyThere = FolioRoutingRule::query()
                    ->where('reservation_id', $locked->id)
                    ->whereNull('reservation_unit_id')
                    ->whereNull('transaction_code_id')
                    ->where('category', $category?->value)
                    ->exists();

                if (! $alreadyThere && ! ($this->createRoutingRule)($locked, $master, RoutingRuleSource::BillingMode, null, $category)->isRefused()) {
                    $added++;
                }
            }

            return new RoutingSyncResult(
                $master,
                $created,
                $added,
                $removed,
                $added > 0 ? $this->unitsWithPostedRoomLines($locked) : [],
                $added > 0 ? $this->unitAdvancesHeld->forReservation($locked) : [],
            );
        });
    }

    private function activeMaster(Reservation $reservation): ?Folio
    {
        return Folio::query()
            ->where('reservation_id', $reservation->id)
            ->where('type', FolioType::Master)
            ->where('status', '<>', FolioStatus::Voided)
            ->oldest('id')
            ->first();
    }

    /**
     * Units with a posted, not reversed Room charge on their own folio.
     *
     * @return list<int>
     */
    private function unitsWithPostedRoomLines(Reservation $reservation): array
    {
        return FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->join('transaction_codes', 'transaction_codes.id', '=', 'folio_lines.transaction_code_id')
            ->where('folios.reservation_id', $reservation->id)
            ->where('folios.type', FolioType::Unit)
            ->where('transaction_codes.category', TransactionCategory::Room)
            ->where('folio_lines.kind', TransactionKind::Charge)
            ->whereNull('folio_lines.reversal_of_id')
            ->whereNotIn('folio_lines.id', FolioLine::query()->select('reversal_of_id')->whereNotNull('reversal_of_id'))
            ->distinct()
            ->orderBy('folios.reservation_unit_id')
            ->pluck('folios.reservation_unit_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
