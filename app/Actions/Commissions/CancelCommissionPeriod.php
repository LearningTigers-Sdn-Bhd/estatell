<?php

namespace App\Actions\Commissions;

use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use App\Models\User;
use App\Services\Commissions\PayoutCycle;
use Illuminate\Support\Facades\DB;

/**
 * Removes a charging period that has not started yet, to fix a wrong start date. A period that has started is kept,
 * because recorded commission depends on it. Returns a refusal code, or null when removed.
 * See docs/30-commission-fees/02-commission-rules.md#charging-periods.
 */
class CancelCommissionPeriod
{
    public const string REFUSAL_ALREADY_STARTED = 'already_started';

    public function __construct(private PayoutCycle $payoutCycle) {}

    public function __invoke(User $admin, PropertyCommissionPeriod $period): ?string
    {
        return DB::transaction(function () use ($admin, $period): ?string {
            Property::query()->lockForUpdate()->findOrFail($period->property_id);
            $locked = PropertyCommissionPeriod::query()->find($period->id);

            if ($locked === null || $locked->starts_on->toDateString() <= $this->payoutCycle->today()->toDateString()) {
                return self::REFUSAL_ALREADY_STARTED;
            }

            $locked->delete();

            activity()
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('deleted')
                ->withProperties(['property_id' => $locked->property_id, 'service' => $locked->service->value, 'starts_on' => $locked->starts_on->toDateString()])
                ->log('Commission start cancelled');

            return null;
        });
    }
}
