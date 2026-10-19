<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * The four stat tiles of the property dashboard. Amounts are decimal strings. `creditLimit` and `creditAvailable` are
 * null when the company has no credit limit; available credit is never below 0.
 */
class PortalDashboardStatsData extends Data
{
    public function __construct(
        public string $openBalance,
        public int $openInvoices,
        public string $overdue,
        public int $overdueInvoices,
        public int $arrivals,
        public int $inHouse,
        public ?string $creditLimit,
        public ?string $creditAvailable,
    ) {}
}
