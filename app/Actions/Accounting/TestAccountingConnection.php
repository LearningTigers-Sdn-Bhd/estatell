<?php

namespace App\Actions\Accounting;

use App\Models\AccountingConnection;
use App\Services\Accounting\AccountingException;

/**
 * Sign in to the accounting software with the saved key, then check that the guest ledger account and every mapped
 * account exist there. Returns null when all is well, or the message to show. The result is saved on the connection.
 */
class TestAccountingConnection
{
    public function __invoke(AccountingConnection $connection): ?string
    {
        if (blank($connection->api_key)) {
            return $this->save($connection, __('Save the :key first.', ['key' => strtolower($connection->provider->keyLabel())]));
        }

        if ($connection->provider->needsCompanySubdomain() && blank($connection->company_subdomain)) {
            return $this->save($connection, __('Save the company subdomain first.'));
        }

        $codes = [];

        foreach ($connection->property->transactionCodes()->whereNotNull('gl_account_code')->pluck('gl_account_code') as $code) {
            if (filled($code)) {
                $codes[] = (string) $code;
            }
        }

        if (filled($connection->guest_ledger_account_code)) {
            $codes[] = (string) $connection->guest_ledger_account_code;
        }

        $codes = array_values(array_unique($codes));

        try {
            $gateway = $connection->provider->gateway($connection);
            $gateway->testConnection();

            $missing = array_values(array_diff($codes, array_keys($gateway->findAccountIds($codes))));

            if ($missing !== []) {
                throw AccountingException::missingAccounts($missing);
            }
        } catch (AccountingException $exception) {
            return $this->save($connection, $exception->getMessage());
        }

        return $this->save($connection, null);
    }

    private function save(AccountingConnection $connection, ?string $error): ?string
    {
        $connection->forceFill(['last_tested_at' => now(), 'last_error' => $error])->save();

        return $error;
    }
}
