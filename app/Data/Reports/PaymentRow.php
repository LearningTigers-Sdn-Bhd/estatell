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
 * `date` is the business (posting) date and `received_on` the day the money came in; a city ledger payment has only
 * `received_on`, so both are that day. `room` is the first night room of a Unit folio, and `guest` the folio's payer
 * (guest, company, or the booker), or the company of a city ledger payment. `folio` and `invoice` are the folio's
 * number and its invoice number (set at the first close); a city ledger payment has neither. A refund has a negative `amount` and `refund_of` is the receipt number of the payment it
 * gives back. `staff_id` is the user who received it, for the totals of each staff member, or 0 when no staff member did
 * (a booking engine payment); it is not a column.
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
        public ?string $refund_of = null,
        public int $staff_id = 0,
        public string $received_on = '',
        public ?string $room = null,
        public string $guest = '',
        public ?string $notes = null,
        public ?string $folio = null,
        public ?string $invoice = null,
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
            'received_on' => CarbonImmutable::parse($this->received_on)->format('j M Y'),
            'receipt' => $this->receipt,
            'room' => $this->room ?? '',
            'folio' => $this->folio ?? '',
            'invoice' => $this->invoice ?? '',
            'guest' => $this->guest,
            'payer' => $this->payer,
            'method' => $this->method,
            'status' => $this->status->label,
            'staff' => $this->staff,
            'amount' => number_format((float) $this->amount, 2),
            'reference' => $this->reference ?? '',
            'notes' => $this->notes ?? '',
            'remarks' => $this->referenceAndNotes(),
            'refund_of' => $this->refund_of ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'received_on' => $this->received_on,
            'receipt' => $this->receipt,
            'room' => $this->room ?? '',
            'folio' => $this->folio ?? '',
            'invoice' => $this->invoice ?? '',
            'guest' => $this->guest,
            'payer' => $this->payer,
            'method' => $this->method,
            'status' => $this->status->label,
            'staff' => $this->staff,
            'amount' => round((float) $this->amount, 2),
            'reference' => $this->reference ?? '',
            'notes' => $this->notes ?? '',
            'remarks' => $this->referenceAndNotes(),
            'refund_of' => $this->refund_of ?? '',
        ];
    }

    /**
     * The Reference / notes (`remarks`) column: the reference, then the notes, joined by a middle dot.
     */
    public function referenceAndNotes(): string
    {
        return implode(' · ', array_filter([$this->reference, $this->notes], fn (?string $part): bool => filled($part)));
    }

    /**
     * @param  string  $date  The posting date of the payment line, as `Y-m-d`.
     * @param  string|null  $room  The first night room or bed of the folio's unit.
     */
    public static function fromPayment(Payment $payment, string $date, ?string $room = null): self
    {
        $folio = $payment->folio;

        return new self(
            key: "p:{$payment->id}",
            date: $date,
            receipt: $payment->receipt_number ?? '',
            payer: $payment->folio->reservation->reference,
            method: $payment->paymentMethod->name,
            status: BadgeData::fromPaymentReportStatus(PaymentReportStatus::fromPaymentStatus($payment->status)),
            staff: $payment->receiver->name ?? __('Booking Engine'),
            amount: $payment->refund_of_id === null ? $payment->amount : (string) BigDecimal::of($payment->amount)->negated(),
            reference: $payment->reference,
            refund_of: $payment->refundOf?->receipt_number,
            staff_id: $payment->received_by ?? 0,
            received_on: $payment->received_on->toDateString(),
            room: $room,
            guest: $folio->payerGuest->full_name ?? $folio->payerCompany->name ?? $folio->reservation->booker->full_name ?? '',
            notes: $payment->notes,
            folio: $folio->number,
            invoice: $folio->invoice_number,
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
            staff_id: $payment->received_by,
            received_on: $payment->received_on->toDateString(),
            guest: $payment->company->name,
            notes: $payment->notes,
        );
    }
}
