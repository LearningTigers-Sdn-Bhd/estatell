<?php

namespace App\Actions\Commissions;

use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use App\Models\User;
use App\Services\Commissions\PayoutCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ends an open charging period on a date, today or later and not before its start. Bookings that check out after it are
 * not charged; commission already recorded stays. Returns a refusal code, or null when stopped.
 * See docs/30-commission-fees/02-commission-rules.md#charging-periods.
 */
class StopCommissionPeriod
{
    public const string REFUSAL_NOT_OPEN = 'not_open';

    public const string REFUSAL_PAST_DATE = 'past_date';

    public const string REFUSAL_BEFORE_START = 'before_start';

    public function __construct(private PayoutCycle $payoutCycle) {}

    public function __invoke(User $admin, PropertyCommissionPeriod $period, CarbonImmutable $endsOn): ?string
    {
        if ($endsOn->toDateString() < $this->payoutCycle->today()->toDateString()) {
            return self::REFUSAL_PAST_DATE;
        }

        return DB::transaction(function () use ($admin, $period, $endsOn): ?string {
            Property::query()->lockForUpdate()->findOrFail($period->property_id);
            $locked = PropertyCommissionPeriod::query()->findOrFail($period->id);

            if ($locked->ends_on !== null) {
                return self::REFUSAL_NOT_OPEN;
            }

            if ($endsOn->toDateString() < $locked->starts_on->toDateString()) {
                return self::REFUSAL_BEFORE_START;
            }

            $locked->update(['ends_on' => $endsOn->toDateString()]);

            activity()
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('updated')
                ->withProperties(['property_id' => $locked->property_id, 'service' => $locked->service->value, 'ends_on' => $endsOn->toDateString()])
                ->log('Commission stopped');

            return null;
        });
    }
}
