<?php

namespace App\Services\Reports;

use App\Data\Shared\BadgeData;

/**
 * What the folio balance reports (Outstanding balance, Deposit liability) know about one folio on a date: its figures
 * from the ledger, and the display facts of its reservation. Dates are `Y-m-d`, null when the reservation has no unit.
 */
final readonly class FolioReportEntry
{
    public function __construct(
        public int $folioId,
        public string $folio,
        public string $reservation,
        public string $payer,
        public ?string $arrival,
        public ?string $departure,
        public BadgeData $status,
        public FolioBalanceOnDate $figures,
        public ?int $daysSinceDeparture,
    ) {}
}
