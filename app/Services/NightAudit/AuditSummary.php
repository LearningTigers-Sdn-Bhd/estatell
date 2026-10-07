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
     * @param  list<array{code: string, name: string, amount: Money}>  $revenue  Revenue by transaction code
     * @param  list<array{name: string, amount: Money}>  $payments  Payments received, by method
     * @param  Collection<int, MealCount>  $meals  Meals to prepare on `$mealDate`
     * @param  Collection<int, NightAuditException>  $exceptions  The exceptions of the audit that are still open
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
