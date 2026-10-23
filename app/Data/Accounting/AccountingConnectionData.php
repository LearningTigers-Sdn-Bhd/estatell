<?php

namespace App\Data\Accounting;

use App\Models\AccountingConnection;
use Spatie\LaravelData\Data;

/**
 * The connection without its API key: the browser only learns whether a key is saved.
 */
class AccountingConnectionData extends Data
{
    public function __construct(
        public string $provider,
        public string $providerLabel,
        public bool $hasApiKey,
        public ?string $companySubdomain,
        public bool $sandbox,
        public ?string $guestLedgerAccountCode,
        public bool $enabled,
        public bool $pushCompanyBilling,
        public ?string $companyBillingSince,
        public ?string $lastTestedAt,
        public ?string $lastError,
    ) {}

    public static function fromModel(AccountingConnection $connection): self
    {
        return new self(
            provider: $connection->provider->value,
            providerLabel: $connection->provider->label(),
            hasApiKey: filled($connection->api_key),
            companySubdomain: $connection->company_subdomain,
            sandbox: $connection->sandbox,
            guestLedgerAccountCode: $connection->guest_ledger_account_code,
            enabled: $connection->enabled,
            pushCompanyBilling: $connection->push_company_billing,
            companyBillingSince: $connection->company_billing_since?->toDateString(),
            lastTestedAt: $connection->last_tested_at?->toIso8601String(),
            lastError: $connection->last_error,
        );
    }
}
