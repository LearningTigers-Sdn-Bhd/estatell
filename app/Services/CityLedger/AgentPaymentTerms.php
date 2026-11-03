<?php

namespace App\Services\CityLedger;

use App\Models\Company;
use App\Models\PropertySetting;

/**
 * The payment terms a company follows on its bookings: the property's agent terms with the company's own where set.
 * Only a company that pays per booking (not direct billing) with its schedule on has terms, as wastays' "Standard"
 * agents. See docs/15-city-ledger/06-agent-payment-schedule.md.
 */
class AgentPaymentTerms
{
    /**
     * @return array{percent: int, hours: int, days: int}|null
     */
    public function for(Company $company, PropertySetting $settings): ?array
    {
        if ($company->direct_bill_enabled || ! $company->payment_schedule_enabled) {
            return null;
        }

        return [
            'percent' => $company->agent_deposit_percent ?? $settings->agent_deposit_percent,
            'hours' => $company->agent_deposit_hours ?? $settings->agent_deposit_hours,
            'days' => $company->agent_full_payment_days ?? $settings->agent_full_payment_days,
        ];
    }
}
