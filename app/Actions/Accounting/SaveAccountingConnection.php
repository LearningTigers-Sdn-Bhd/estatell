<?php

namespace App\Actions\Accounting;

use App\Enums\AccountingProvider;
use App\Models\AccountingConnection;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Create or update the property's accounting connection. A blank `api_key` keeps the saved key, so the form never has to
 * show it. Changing the provider drops the saved key, because it belongs to the old provider. Saving does not test the connection: use `TestAccountingConnection`.
 */
class SaveAccountingConnection
{
    /**
     * @param  array{provider: string, api_key?: string|null, company_subdomain?: string|null, sandbox?: bool, guest_ledger_account_code?: string|null, enabled: bool, push_company_billing?: bool}  $data
     */
    public function __invoke(Property $property, User $actor, array $data): AccountingConnection
    {
        $connection = $property->accountingConnection ?? new AccountingConnection(['property_id' => $property->id]);
        $provider = AccountingProvider::from($data['provider']);
        $providerChanged = $connection->exists && $connection->provider !== $provider;

        $keyChanged = filled($data['api_key'] ?? null);

        $connection->forceFill([
            'property_id' => $property->id,
            'provider' => $provider,
            'company_subdomain' => $provider->needsCompanySubdomain() && filled($data['company_subdomain'] ?? null) ? trim((string) $data['company_subdomain']) : null,
            'sandbox' => $provider->supportsSandbox() && ($data['sandbox'] ?? false),
            'guest_ledger_account_code' => filled($data['guest_ledger_account_code'] ?? null) ? trim((string) $data['guest_ledger_account_code']) : null,
            'enabled' => $data['enabled'],
            'push_company_billing' => $data['push_company_billing'] ?? false,
        ]);

        if ($connection->push_company_billing && $connection->company_billing_since === null) {
            $connection->company_billing_since = CarbonImmutable::parse((string) ($property->businessDates()->current()->value('date') ?? now()->toDateString()));
        }

        if ($keyChanged) {
            $connection->api_key = trim((string) $data['api_key']);
            $connection->last_error = null;
        } elseif ($providerChanged) {
            $connection->api_key = null;
            $connection->last_error = null;
        }

        $connection->save();

        activity()
            ->performedOn($connection)
            ->causedBy($actor)
            ->event('accounting.connection-saved')
            ->withProperties(['property_id' => $property->id, 'provider' => $connection->provider->value, 'enabled' => $connection->enabled, 'push_company_billing' => $connection->push_company_billing, 'key_changed' => $keyChanged, 'provider_changed' => $providerChanged])
            ->log('Accounting connection saved');

        return $connection;
    }
}
