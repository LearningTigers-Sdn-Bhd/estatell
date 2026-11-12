<?php

namespace App\Data\Portal;

use App\Data\CityLedger\StatementData;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * The three figures above the portal statement, as at the statement's end date (`asOf`): the balance (open invoices
 * less credit), what is overdue (the aging buckets past their due date) and the credit (unallocated payments, as a
 * positive amount). All come from the statement's aging. See docs/23-company-portal/01-company-portal.md#statements.
 */
class PortalAccountSummaryData extends Data
{
    public function __construct(
        public string $asOf,
        public string $balance,
        public string $overdue,
        public string $credit,
    ) {}

    public static function fromStatement(StatementData $statement): self
    {
        $aging = $statement->aging;
        $overdue = BigDecimal::of($aging->days1to30)->plus($aging->days31to60)->plus($aging->days61to90)->plus($aging->over90);

        return new self(
            asOf: $statement->to,
            balance: (string) BigDecimal::of($aging->total)->toScale(2),
            overdue: (string) $overdue->toScale(2),
            credit: (string) BigDecimal::of($aging->credit)->abs()->toScale(2),
        );
    }
}
