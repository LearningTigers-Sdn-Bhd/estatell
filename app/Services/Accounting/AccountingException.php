<?php

namespace App\Services\Accounting;

use RuntimeException;

/**
 * An expected failure of the accounting software or of the setup. The message is safe to show to staff.
 */
class AccountingException extends RuntimeException
{
    /**
     * @param  list<string>  $codes
     */
    public static function unmappedCodes(array $codes): self
    {
        return new self(__('No accounting account is set for: :codes.', ['codes' => implode(', ', $codes)]));
    }

    /**
     * @param  list<string>  $codes
     */
    public static function missingAccounts(array $codes): self
    {
        return new self(__('These accounts do not exist in the accounting software: :codes.', ['codes' => implode(', ', $codes)]));
    }

    public static function guestLedgerMissing(): self
    {
        return new self(__('Set the guest ledger account before pushing.'));
    }
}
