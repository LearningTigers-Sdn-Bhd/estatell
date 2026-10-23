<?php

namespace App\Services\Accounting;

use App\Enums\AccountingSetupStatus;
use App\Models\AccountingConnection;
use App\Models\AccountingSync;
use App\Models\Property;

/**
 * The setup summary shown above every Accounting tab: the status, the account progress and the last push. It reads only.
 */
class AccountingSetup
{
    public function __construct(private readonly MappableTransactionCodes $mappableCodes) {}

    public function status(?AccountingConnection $connection): AccountingSetupStatus
    {
        if ($connection === null || blank($connection->api_key)) {
            return AccountingSetupStatus::NotConnected;
        }

        if (filled($connection->last_error)) {
            return AccountingSetupStatus::NeedsAttention;
        }

        return $connection->enabled ? AccountingSetupStatus::Live : AccountingSetupStatus::Ready;
    }

    /**
     * Whether the connection has everything it needs to be tested: a key, the guest ledger account and, for a provider that
     * names the company by subdomain, the subdomain.
     */
    public function isComplete(?AccountingConnection $connection): bool
    {
        return $connection !== null
            && filled($connection->api_key)
            && filled($connection->guest_ledger_account_code)
            && (! $connection->provider->needsCompanySubdomain() || filled($connection->company_subdomain));
    }

    /**
     * @return array{mapped: int, total: int}
     */
    public function accountProgress(Property $property): array
    {
        $codes = $this->mappableCodes->query($property);

        return [
            'total' => (clone $codes)->count(),
            'mapped' => (clone $codes)->whereNotNull('gl_account_code')->where('gl_account_code', '!=', '')->count(),
        ];
    }

    public function lastSync(Property $property): ?AccountingSync
    {
        return AccountingSync::query()
            ->where('property_id', $property->id)
            ->with('businessDate')
            ->latest('business_date_id')
            ->first();
    }
}
