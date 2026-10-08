<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Enums\PaymentReportStatus;
use App\Models\ArPayment;
use App\Models\Payment;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Payments report: a folio payment or refund, or a city ledger payment. The property names are the
 * column keys of `ReportKey::Payments`. `key` is `p:{id}` for a folio payment and `ar:{id}` for a city ledger payment.
 * `date` is the business date. A refund has a negative `amount`.
 */
class PaymentRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $date,
        public string $receipt,
        public string $payer,
        public string $method,
        public BadgeData $status,
        public string $staff,
        public string $amount,
        public ?string $reference,
    ) {}

    /**
     * Whether the row counts in the totals: only Completed payments do.
     */
    public function countsInTotals(): bool
    {
        return $this->status->value === PaymentReportStatus::Completed->value;
    }

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => CarbonImmutable::parse($this->date)->format('j M Y'),
            'receipt' => $this->receipt,
            'payer' => $this->payer,
            'method' => $this->method,
            'status' => $this->status->label,
            'staff' => $this->staff,
            'amount' => number_format((float) $this->amount, 2),
            'reference' => $this->reference ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'receipt' => $this->receipt,
            'payer' => $this->payer,
            'method' => $this->method,
            'status' => $this->status->label,
            'staff' => $this->staff,
            'amount' => round((float) $this->amount, 2),
            'reference' => $this->reference ?? '',
        ];
    }

    /**
     * @param  string  $date  The posting date of the payment line, as `Y-m-d`.
     */
    public static function fromPayment(Payment $payment, string $date): self
    {
        return new self(
            key: "p:{$payment->id}",
            date: $date,
            receipt: $payment->receipt_number ?? '',
            payer: $payment->folio->reservation->reference,
            method: $payment->paymentMethod->name,
            status: BadgeData::fromPaymentReportStatus(PaymentReportStatus::fromPaymentStatus($payment->status)),
            staff: $payment->receiver->name,
            amount: $payment->refund_of_id === null ? $payment->amount : (string) BigDecimal::of($payment->amount)->negated(),
            reference: $payment->reference,
        );
    }

    public static function fromArPayment(ArPayment $payment): self
    {
        return new self(
            key: "ar:{$payment->id}",
            date: $payment->received_on->toDateString(),
            receipt: $payment->receipt_number,
            payer: $payment->company->name,
            method: $payment->paymentMethod->name,
            status: BadgeData::fromPaymentReportStatus($payment->voided_at === null ? PaymentReportStatus::Completed : PaymentReportStatus::Voided),
            staff: $payment->receiver->name,
            amount: $payment->amount,
            reference: $payment->reference,
        );
    }
}
