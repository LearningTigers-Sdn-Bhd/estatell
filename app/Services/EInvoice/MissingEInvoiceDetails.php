<?php

namespace App\Services\EInvoice;

use App\Actions\EInvoice\EInvoiceResult;
use InvalidArgumentException;

/**
 * A detail LHDN needs is missing, found before anything is sent. The message tells staff what to fill in and where;
 * `refusal` is the `EInvoiceResult` code the sending Action returns.
 */
class MissingEInvoiceDetails extends InvalidArgumentException
{
    public function __construct(public readonly string $refusal, string $message)
    {
        parent::__construct($message);
    }

    /**
     * The hotel's own details, kept in E-invoice settings.
     */
    public static function hotel(?string $taxNumber, ?string $email, ?string $city): void
    {
        $missing = array_keys(array_filter([
            __('tax number') => blank($taxNumber),
            __('email') => blank($email),
            __('city') => blank($city),
        ]));

        if ($missing !== []) {
            throw new self(EInvoiceResult::REFUSAL_SUPPLIER_PROFILE_INCOMPLETE, count($missing) === 1
                ? __("Your hotel's :detail is missing. Add it in E-invoice settings, then send again.", ['detail' => $missing[0]])
                : __("Your hotel's :details are missing. Add them in E-invoice settings, then send again.", ['details' => implode(__(' and '), $missing)]));
        }
    }
}
