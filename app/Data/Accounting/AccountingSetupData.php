<?php

namespace App\Data\Accounting;

use App\Data\Shared\BadgeData;
use App\Models\Property;
use App\Services\Accounting\AccountingSetup;
use Spatie\LaravelData\Data;

/**
 * The summary above every Accounting tab. `connectionComplete` is true once the form has everything a test needs.
 */
class AccountingSetupData extends Data
{
    public function __construct(
        public BadgeData $status,
        public ?string $providerLabel,
        public bool $connectionComplete,
        public bool $enabled,
        public int $accountsMapped,
        public int $accountsTotal,
        public ?string $lastTestedAt,
        public ?string $lastError,
        public ?AccountingSyncData $lastSync,
    ) {}

    public static function forProperty(Property $property, AccountingSetup $setup): self
    {
        $connection = $property->accountingConnection;
        $progress = $setup->accountProgress($property);
        $lastSync = $setup->lastSync($property);

        return new self(
            status: BadgeData::fromAccountingSetupStatus($setup->status($connection)),
            providerLabel: $connection?->provider->label(),
            connectionComplete: $setup->isComplete($connection),
            enabled: $connection !== null && $connection->enabled,
            accountsMapped: $progress['mapped'],
            accountsTotal: $progress['total'],
            lastTestedAt: $connection?->last_tested_at?->toIso8601String(),
            lastError: $connection?->last_error,
            lastSync: $lastSync === null ? null : AccountingSyncData::fromModel($lastSync),
        );
    }
}
