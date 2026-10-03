<?php

namespace App\Actions\Folio;

use App\Enums\RoutingRuleSource;
use App\Models\FolioRoutingRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveRoutingRule
{
    /**
     * Remove a Manual routing rule. The rule row is locked first. A BillingMode rule is refused with
     * `rule_not_manual` (change the billing mode to change it), and a rule that is already gone with `rule_not_found`.
     * A refusal writes and logs nothing. Posted lines stay where they are. See docs/13-folio/03-routing.md#staff-screens.
     */
    public function __invoke(User $actor, FolioRoutingRule $rule): RoutingRuleResult
    {
        return DB::transaction(function () use ($actor, $rule): RoutingRuleResult {
            $current = FolioRoutingRule::query()->lockForUpdate()->find($rule->id);

            if ($current === null) {
                return RoutingRuleResult::refused(RoutingRuleResult::REFUSAL_RULE_NOT_FOUND);
            }

            if ($current->source !== RoutingRuleSource::Manual) {
                return RoutingRuleResult::refused(RoutingRuleResult::REFUSAL_RULE_NOT_MANUAL);
            }

            $current->delete();

            activity()
                ->performedOn($current->reservation)
                ->causedBy($actor)
                ->event('routing_rule.removed')
                ->withProperties([
                    'property_id' => $current->reservation->property_id,
                    'routing_rule_id' => $current->id,
                    'reservation_unit_id' => $current->reservation_unit_id,
                    'category' => $current->category?->value,
                    'transaction_code_id' => $current->transaction_code_id,
                    'target_folio_id' => $current->target_folio_id,
                ])
                ->log('Routing rule removed');

            return RoutingRuleResult::removed($current);
        });
    }
}
