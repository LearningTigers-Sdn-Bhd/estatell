<?php

namespace App\Actions\Accounting;

use App\Jobs\ReconcileCompanyBillingJob;
use App\Models\AccountingConnection;

/**
 * Queue a company billing sync for a property when it has company billing switched on. The city ledger Actions call it after
 * they change an invoice, a credit note or a payment. It does nothing for a property without accounting, and the job is
 * queued after the surrounding transaction commits.
 */
class QueueCompanyBillingSync
{
    public function __invoke(int $propertyId): void
    {
        $switchedOn = AccountingConnection::query()
            ->where('property_id', $propertyId)
            ->where('enabled', true)
            ->where('push_company_billing', true)
            ->exists();

        if ($switchedOn) {
            ReconcileCompanyBillingJob::dispatch($propertyId)->afterCommit();
        }
    }
}
