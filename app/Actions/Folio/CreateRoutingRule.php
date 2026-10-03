<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Folio\RoutingRuleCheck;
use Illuminate\Support\Facades\DB;

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

    /**
     * Save a Manual rule that staff made. The target folio row is locked and must be Open: a Closed or Voided target
     * is refused with `target_folio_not_open`, because a posting to it would be refused. A saved rule is logged on the
     * reservation. Later postings follow it; posted lines stay. See docs/13-folio/03-routing.md#staff-screens.
     */
    public function manual(User $actor, Reservation $reservation, Folio $targetFolio, ?int $reservationUnitId = null, ?TransactionCategory $category = null, ?int $transactionCodeId = null): RoutingRuleResult
    {
        return DB::transaction(function () use ($actor, $reservation, $targetFolio, $reservationUnitId, $category, $transactionCodeId): RoutingRuleResult {
            $target = Folio::query()->lockForUpdate()->findOrFail($targetFolio->id);

            if ($target->reservation_id === $reservation->id && $target->status !== FolioStatus::Open) {
                return RoutingRuleResult::refused(RoutingRuleResult::REFUSAL_TARGET_FOLIO_NOT_OPEN);
            }

            $result = $this($reservation, $target, RoutingRuleSource::Manual, $reservationUnitId, $category, $transactionCodeId);

            if (! $result->isRefused()) {
                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('routing_rule.created')
                    ->withProperties([
                        'property_id' => $reservation->property_id,
                        'routing_rule_id' => $result->rule?->id,
                        'reservation_unit_id' => $reservationUnitId,
                        'category' => $category?->value,
                        'transaction_code_id' => $transactionCodeId,
                        'target_folio_id' => $target->id,
                    ])
                    ->log('Routing rule added');
            }

            return $result;
        });
    }
}
