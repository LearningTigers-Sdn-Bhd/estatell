<?php

namespace App\Services\Commissions;

use Carbon\CarbonImmutable;

/**
 * The payout calendar: cycles run from the 16th to the 15th and are paid on the 1st, moved to Monday when the 1st is a
 * weekend. There is no public holiday calendar. See docs/30-commission-fees/04-payout-vouchers.md#cycle.
 */
class PayoutCycle
{
    public const string TIME_ZONE = 'Asia/Kuala_Lumpur';

    /**
     * The cut-off of the last cycle that has ended on or before this day: the 15th of this month from the 16th, or the
     * 15th of last month before it.
     */
    public function lastEndedOn(CarbonImmutable $day): CarbonImmutable
    {
        return ($day->day >= 16 ? $day : $day->subMonthNoOverflow())->setDay(15)->startOfDay();
    }

    /**
     * The cut-off of the cycle this day belongs to: the coming 15th, or this day when it is the 15th.
     */
    public function currentEndOn(CarbonImmutable $day): CarbonImmutable
    {
        return ($day->day <= 15 ? $day : $day->addMonthNoOverflow())->setDay(15)->startOfDay();
    }

    public function startOf(CarbonImmutable $periodEnd): CarbonImmutable
    {
        return $periodEnd->subMonthNoOverflow()->addDay();
    }

    /**
     * The 1st after the cut-off, moved to Monday when it falls on a weekend.
     */
    public function payoutDate(CarbonImmutable $periodEnd): CarbonImmutable
    {
        $date = $periodEnd->startOfMonth()->addMonthNoOverflow();

        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }

    public function today(): CarbonImmutable
    {
        return now(self::TIME_ZONE)->toImmutable()->startOfDay();
    }
}
