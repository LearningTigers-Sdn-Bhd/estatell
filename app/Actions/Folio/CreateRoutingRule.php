<?php

namespace App\Actions\Folio;

use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\Reservation;
use App\Services\Folio\RoutingRuleCheck;

class CreateRoutingRule
{
    public function __construct(private RoutingRuleCheck $check) {}

    /**
     * Save one routing rule of a reservation. A null unit, category or code matches all. A target folio of another
     * reservation, a unit of another reservation, a code of another property, or a second rule with the same selectors
     * is refused: nothing is written. `SyncRoutingRules` uses it for BillingMode rules, and the later staff screens
     * for Manual rules. See docs/13-folio/03-routing.md.
     */
    public function __invoke(Reservation $reservation, Folio $targetFolio, RoutingRuleSource $source, ?int $reservationUnitId = null, ?TransactionCategory $category = null, ?int $transactionCodeId = null): RoutingRuleResult
    {
        $refusal = $this->check->refusal($reservation, $targetFolio, $reservationUnitId, $transactionCodeId);

        if ($refusal !== null) {
            return RoutingRuleResult::refused($refusal);
        }

        $exists = FolioRoutingRule::query()
            ->where('reservation_id', $reservation->id)
            ->where('reservation_unit_id', $reservationUnitId)
            ->where('category', $category?->value)
            ->where('transaction_code_id', $transactionCodeId)
            ->exists();

        if ($exists) {
            return RoutingRuleResult::refused(RoutingRuleResult::REFUSAL_RULE_EXISTS);
        }

        $rule = FolioRoutingRule::query()->create([
            'reservation_id' => $reservation->id,
            'reservation_unit_id' => $reservationUnitId,
            'category' => $category,
            'transaction_code_id' => $transactionCodeId,
            'target_folio_id' => $targetFolio->id,
            'source' => $source,
        ]);

        return RoutingRuleResult::created($rule);
    }
}
