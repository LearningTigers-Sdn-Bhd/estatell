<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Services\Reports\FolioReportEntry;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Deposit liability report: a folio in credit on the report date. The property names are the column
 * keys of `ReportKey::DepositLiability`. `key` is the folio id. `received`, `refunded`, `charged`, `deposit` and `held`
 * are all positive amounts; `held` is the credit (minus the balance) and `deposit` is the security deposit still held.
 */
class DepositLiabilityRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $folio,
        public string $reservation,
        public string $payer,
        public ?string $arrival,
        public ?string $departure,
        public BadgeData $status,
        public string $received,
        public string $refunded,
        public string $charged,
        public string $deposit,
        public string $held,
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
            'received' => number_format((float) $this->received, 2),
            'refunded' => number_format((float) $this->refunded, 2),
            'charged' => number_format((float) $this->charged, 2),
            'deposit' => number_format((float) $this->deposit, 2),
            'held' => number_format((float) $this->held, 2),
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
            'received' => round((float) $this->received, 2),
            'refunded' => round((float) $this->refunded, 2),
            'charged' => round((float) $this->charged, 2),
            'deposit' => round((float) $this->deposit, 2),
            'held' => round((float) $this->held, 2),
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
            received: (string) $entry->figures->received,
            refunded: (string) $entry->figures->refunded,
            charged: (string) $entry->figures->charges,
            deposit: (string) $entry->figures->securityDeposit,
            held: (string) $entry->figures->balance->negated(),
        );
    }
}
