<?php

namespace App\Data\Reports;

use App\Models\FolioLine;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Folio ledger report: one folio line of any kind. The property names are the column keys of
 * `ReportKey::FolioLedger`. `key` is the folio line id. `amount` is signed, as in the ledger.
 */
class FolioLedgerRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $date,
        public string $folio,
        public string $reservation,
        public string $guest,
        public string $code,
        public string $description,
        public string $kind,
        public ?string $staff,
        public string $amount,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => CarbonImmutable::parse($this->date)->format('j M Y'),
            'folio' => $this->folio,
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'code' => $this->code,
            'description' => $this->description,
            'kind' => $this->kind,
            'staff' => $this->staff ?? '',
            'amount' => number_format((float) $this->amount, 2),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'folio' => $this->folio,
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'code' => $this->code,
            'description' => $this->description,
            'kind' => $this->kind,
            'staff' => $this->staff ?? '',
            'amount' => round((float) $this->amount, 2),
        ];
    }

    /**
     * The folio, its reservation, its payer guest or company, the transaction code and the user must be loaded. A
     * folio with no payer shows the booker of the reservation.
     */
    public static function fromModel(FolioLine $line): self
    {
        $folio = $line->folio;

        return new self(
            key: $line->id,
            date: $line->posting_date->toDateString(),
            folio: $folio->number,
            reservation: $folio->reservation->reference,
            guest: $folio->payerCompany?->name ?? $folio->payerGuest?->full_name ?? $folio->reservation->booker->full_name,
            code: $line->transactionCode->code,
            description: $line->description,
            kind: $line->kind->label(),
            staff: $line->user?->name,
            amount: $line->amount,
        );
    }
}
