<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * An Open Master or Third party folio that still holds money, listed on the group check-out page once every room is
 * out. The field names follow `FolioData` so the Record payment and Settle to City ledger Dialogs take either.
 * `name` labels the folio with its type and payer ("Master · Acme Sdn Bhd"). `canRecordPayment` needs `payment.record`
 * and a purpose the folio offers. `canSettleToCityLedger` needs a company payer with direct bill, a balance above 0
 * and `city-ledger.invoice`; `settleCreditWarning` is then set when the company is over its credit limit (a warning
 * only). `url` is the folio tab, opened in a new tab.
 */
class GroupCheckOutFolioData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $paymentPurposes
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $balance,
        public ?string $payerCompanyName,
        public bool $canRecordPayment,
        public bool $canSettleToCityLedger,
        public ?int $paymentUnitId,
        public array $paymentPurposes,
        public ?string $defaultPaymentPurpose,
        public ?string $settleCreditWarning,
        public string $url,
    ) {}
}
