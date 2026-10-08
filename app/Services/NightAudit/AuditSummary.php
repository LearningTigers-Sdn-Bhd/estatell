<?php

namespace App\Services\NightAudit;

use App\Models\DailyStatistic;
use App\Models\NightAuditException;
use App\Services\Meals\MealCount;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the morning message says about a finished night audit.
 */
class AuditSummary
{
    /**
     * @param  list<array{code: string, name: string, amount: Money, lines: int}>  $revenue  Revenue by transaction code
     * @param  list<array{name: string, count: int, amount: Money}>  $payments  Payments received, by method: folio payments and city ledger payments
     * @param  Collection<int, MealCount>  $meals  Meals to prepare on `$mealDate`
     * @param  Collection<int, NightAuditException>  $exceptions  The exceptions of the audit: only the open ones for the message, all of them for the packet
     */
    public function __construct(
        public readonly CarbonImmutable $businessDate,
        public readonly CarbonImmutable $mealDate,
        public readonly string $currency,
        public readonly ?DailyStatistic $statistics,
        public readonly array $revenue,
        public readonly array $payments,
        public readonly Money $refunds,
        public readonly Collection $meals,
        public readonly Collection $exceptions,
    ) {}
}
