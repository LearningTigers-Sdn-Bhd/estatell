<?php

namespace App\Services\EInvoice;

use App\Actions\EInvoice\EInvoiceResult;
use App\Models\Property;
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
     * The hotel's own details (`SupplierDetails`), kept in E-invoice settings. Names the first problem.
     */
    public static function hotel(Property $property): void
    {
        $problems = app(SupplierDetails::class)->missing($property);

        if ($problems !== []) {
            throw new self(EInvoiceResult::REFUSAL_SUPPLIER_PROFILE_INCOMPLETE, __("The hotel's e-invoice details are not complete: :problem Complete them in E-invoice settings, then send again.", [
                'problem' => lcfirst((string) reset($problems)),
            ]));
        }
    }
}
