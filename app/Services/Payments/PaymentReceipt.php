<?php

namespace App\Services\Payments;

use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Reads a payment and gathers what its receipt shows. The amounts come from the ledger: the surcharge is the
 * surcharge charge line, its tax the line's tax children, and the amount paid on the bill is the total collected
 * minus both. It writes nothing. See docs/14-payments/01-payments-and-refunds.md#receipt.
 */
class PaymentReceipt
{
    public function __construct(private CountryOptions $countryOptions) {}

    /**
     * @throws InvalidArgumentException for a refund, which has no receipt
     */
    public function forPayment(Payment $payment): ReceiptDocument
    {
        if ($payment->refund_of_id !== null || $payment->receipt_number === null) {
            throw new InvalidArgumentException('A refund has no receipt.');
        }

        $payment->loadMissing(['folio.property.settings', 'folio.reservation.booker', 'folio.payerGuest', 'paymentMethod', 'receiver', 'folioLine', 'surchargeLine.children']);
        $folio = $payment->folio;
        $property = $folio->property;
        $timeZone = $property->settings->time_zone ?? config('app.timezone');
        [$surcharge, $surchargeTax] = $this->surcharge($payment->surchargeLine);
        $paid = BigDecimal::of($payment->amount)->minus($surcharge ?? '0')->minus($surchargeTax ?? '0');

        return new ReceiptDocument(
            propertyName: $property->name,
            addressLines: array_values(array_filter([
                $property->address_line1,
                $property->address_line2,
                trim(($property->postal_code ?? '').' '.($property->city ?? '')),
                $this->countryOptions->name($property->country_code),
            ], filled(...))),
            contactLines: array_values(array_filter([$property->contact_phone, $property->contact_email], filled(...))),
            registrationLines: $this->registrationLines($property),
            receiptNumber: $payment->receipt_number,
            postingDate: $payment->folioLine->posting_date->format('j M Y'),
            receivedAt: $payment->received_at->setTimezone($timeZone)->format('j M Y, H:i'),
            payerName: $folio->payerGuest->full_name ?? $folio->reservation->booker->full_name,
            reservationReference: $folio->reservation->reference,
            folioNumber: $folio->number,
            method: $payment->paymentMethod->name,
            reference: $payment->reference,
            purpose: $payment->purpose->label(),
            currency: $property->settings?->currency->value ?? '',
            amountPaid: (string) $paid->toScale(2),
            surcharge: $surcharge,
            surchargeTax: $surchargeTax,
            total: (string) $payment->amount,
            receivedBy: $payment->receiver->name,
        );
    }

    /**
     * The registration number and the TIN, each only when set.
     *
     * @return list<string>
     */
    private function registrationLines(Property $property): array
    {
        $lines = [];

        if (filled($property->registration_number)) {
            $lines[] = __('Registration no. :number', ['number' => $property->registration_number]);
        }

        if (filled($property->tin)) {
            $lines[] = __('TIN :number', ['number' => $property->tin]);
        }

        return $lines;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function surcharge(?FolioLine $line): array
    {
        if ($line === null) {
            return [null, null];
        }

        $tax = $line->children->reduce(fn (BigDecimal $sum, FolioLine $child): BigDecimal => $sum->plus($child->amount), BigDecimal::zero());

        return [(string) $line->amount, (string) $tax->toScale(2)];
    }
}
