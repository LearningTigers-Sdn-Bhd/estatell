<?php

namespace App\Services\CityLedger;

use App\Models\Company;

/**
 * Asks whether an action keeps a company under its credit limit. A company with no limit always passes. It writes
 * nothing: the Actions that call it decide what a refusal means and whether an override applies.
 * See docs/15-city-ledger/04-credit-control-and-reports.md#credit-check.
 */
class CreditCheck
{
    public function __construct(private CreditExposure $creditExposure) {}

    /**
     * Refused when the exposure plus `$additional` is above the limit.
     */
    public function check(Company $company, string $additional = '0.00'): CreditCheckResult
    {
        $figures = $this->creditExposure->forCompany($company);
        $additional = bcadd($additional, '0', 2);

        if ($figures['limit'] === null) {
            return new CreditCheckResult(true, $figures['exposure'], null, $additional, '0.00');
        }

        $over = bcsub(bcadd($figures['exposure'], $additional, 2), $figures['limit'], 2);
        $allowed = bccomp($over, '0', 2) <= 0;

        return new CreditCheckResult($allowed, $figures['exposure'], $figures['limit'], $additional, $allowed ? '0.00' : $over);
    }
}
