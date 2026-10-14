<?php

namespace App\Services\CityLedger;

use App\Models\ArPayment;

/**
 * Reads an AR payment and gathers what its receipt shows: the company, the method,
 * reference, date, amount and who received it. It writes nothing. See docs/15-city-ledger/03-ar-payments.md#pages.
 */
class ArPaymentReceipt
{
    public function forPayment(ArPayment $payment): ArReceiptDocument
    {
        $payment->loadMissing(['property.settings', 'company:id,name', 'paymentMethod:id,name', 'receiver:id,name']);

        return new ArReceiptDocument(
            receiptNumber: $payment->receipt_number,
            receivedOn: $payment->received_on->format('j M Y'),
            companyName: $payment->company->name,
            method: $payment->paymentMethod->name,
            reference: $payment->reference,
            notes: $payment->notes,
            currency: $payment->property->settings?->currency->getCurrencyCode() ?? '',
            total: (string) $payment->amount,
            receivedBy: $payment->receiver->name,
            isVoid: $payment->voided_at !== null,
        );
    }
}
