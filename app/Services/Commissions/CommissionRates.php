<?php

namespace App\Services\Commissions;

use App\Data\Commissions\CommissionRateData;
use App\Enums\CommissionRateStatus;
use App\Enums\CommissionService;
use App\Models\CommissionRate;
use Carbon\CarbonImmutable;

/**
 * Reads platform commission rates. A booking uses the rate in force on the day it was made.
 * See docs/30-commission-fees/02-commission-rules.md#rates.
 */
class CommissionRates
{
    /**
     * The rate for a booking made on this date, or null when no rate had started yet.
     */
    public function rateOn(CommissionService $service, CarbonImmutable $bookedOn): ?string
    {
        return CommissionRate::query()
            ->where('service', $service)
            ->whereDate('effective_from', '<=', $bookedOn->toDateString())
            ->orderByDesc('effective_from')
            ->value('rate');
    }

    /**
     * Every rate row, newest first per service, marked Scheduled, Current or Past against today.
     *
     * @return list<CommissionRateData>
     */
    public function all(): array
    {
        $today = today()->toDateString();
        $currentSeen = [];

        return array_values(CommissionRate::query()
            ->orderBy('service')
            ->orderByDesc('effective_from')
            ->get()
            ->map(function (CommissionRate $rate) use ($today, &$currentSeen): CommissionRateData {
                $status = match (true) {
                    $rate->effective_from->toDateString() > $today => CommissionRateStatus::Scheduled,
                    isset($currentSeen[$rate->service->value]) => CommissionRateStatus::Past,
                    default => CommissionRateStatus::Current,
                };

                if ($status === CommissionRateStatus::Current) {
                    $currentSeen[$rate->service->value] = true;
                }

                return CommissionRateData::fromModel($rate, $status);
            })
            ->all());
    }
}
