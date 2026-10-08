<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Services\Reports\FolioReportEntry;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Outstanding balance report: a folio that owes on the report date. The property names are the column
 * keys of `ReportKey::OutstandingBalance`. `key` is the folio id. `payments` is negative (paid minus refunded), so
 * `charges + payments = balance`. `days` is empty until the departure date has come.
 */
class OutstandingBalanceRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $folio,
        public string $reservation,
        public string $payer,
        public ?string $arrival,
        public ?string $departure,
        public BadgeData $status,
        public string $charges,
        public string $payments,
        public string $balance,
        public ?int $days,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'folio' => $this->folio,
            'reservation' => $this->reservation,
            'payer' => $this->payer,
            'arrival' => $this->arrival === null ? '' : CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'departure' => $this->departure === null ? '' : CarbonImmutable::parse($this->departure)->format('j M Y'),
            'status' => $this->status->label,
            'charges' => number_format((float) $this->charges, 2),
            'payments' => number_format((float) $this->payments, 2),
            'balance' => number_format((float) $this->balance, 2),
            'days' => $this->days === null ? '' : (string) $this->days,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'folio' => $this->folio,
            'reservation' => $this->reservation,
            'payer' => $this->payer,
            'arrival' => $this->arrival ?? '',
            'departure' => $this->departure ?? '',
            'status' => $this->status->label,
            'charges' => round((float) $this->charges, 2),
            'payments' => round((float) $this->payments, 2),
            'balance' => round((float) $this->balance, 2),
            'days' => $this->days ?? '',
        ];
    }

    public static function fromEntry(FolioReportEntry $entry): self
    {
        return new self(
            key: $entry->folioId,
            folio: $entry->folio,
            reservation: $entry->reservation,
            payer: $entry->payer,
            arrival: $entry->arrival,
            departure: $entry->departure,
            status: $entry->status,
            charges: (string) $entry->figures->charges,
            payments: (string) $entry->figures->payments(),
            balance: (string) $entry->figures->balance,
            days: $entry->daysSinceDeparture,
        );
    }
}
