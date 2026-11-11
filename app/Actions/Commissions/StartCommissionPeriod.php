<?php

namespace App\Actions\Commissions;

use App\Enums\CommissionService;
use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use App\Models\User;
use App\Services\Commissions\PayoutCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Starts charging a property commission for a service from a date, with no end. The date is today or later, the service
 * has no open period, and the new period does not overlap a past one. Returns a refusal code, or null when started.
 * See docs/30-commission-fees/02-commission-rules.md#charging-periods.
 */
class StartCommissionPeriod
{
    public const string REFUSAL_PAST_DATE = 'past_date';

    public const string REFUSAL_ALREADY_OPEN = 'already_open';

    public const string REFUSAL_OVERLAPS = 'overlaps';

    public function __construct(private PayoutCycle $payoutCycle) {}

    public function __invoke(User $admin, Property $property, CommissionService $service, CarbonImmutable $startsOn): ?string
    {
        if ($startsOn->toDateString() < $this->payoutCycle->today()->toDateString()) {
            return self::REFUSAL_PAST_DATE;
        }

        return DB::transaction(function () use ($admin, $property, $service, $startsOn): ?string {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $periods = PropertyCommissionPeriod::query()->where('property_id', $property->id)->where('service', $service);

            if ((clone $periods)->whereNull('ends_on')->exists()) {
                return self::REFUSAL_ALREADY_OPEN;
            }

            if ((clone $periods)->whereDate('ends_on', '>=', $startsOn->toDateString())->exists()) {
                return self::REFUSAL_OVERLAPS;
            }

            $period = PropertyCommissionPeriod::query()->create([
                'property_id' => $property->id,
                'service' => $service,
                'starts_on' => $startsOn->toDateString(),
                'created_by' => $admin->id,
            ]);

            activity()
                ->performedOn($period)
                ->causedBy($admin)
                ->event('created')
                ->withProperties(['property_id' => $property->id, 'service' => $service->value, 'starts_on' => $startsOn->toDateString()])
                ->log('Commission started');

            return null;
        });
    }
}
