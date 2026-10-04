<?php

namespace App\Actions\CityLedger;

use App\Models\Company;
use App\Models\User;
use App\Services\CityLedger\CreditCheckResult;

class RecordCreditOverride
{
    /**
     * Log that a user passed a credit limit check with a reason, on the company, with the figures that were refused.
     * The caller runs it inside the transaction of the action that went ahead, and only when the check refused and
     * the reason is not blank. See docs/15-city-ledger/04-credit-control-and-reports.md#credit-check.
     */
    public function __invoke(User $actor, Company $company, CreditCheckResult $check, string $reason, string $action): void
    {
        activity()
            ->performedOn($company)
            ->causedBy($actor)
            ->event('credit.overridden')
            ->withProperties([
                'property_id' => $company->property_id,
                'action' => $action,
                'exposure' => $check->exposure,
                'limit' => $check->limit,
                'additional' => $check->additional,
                'over_by' => $check->overBy,
                'reason' => trim($reason),
            ])
            ->log('Credit limit overridden');
    }
}
