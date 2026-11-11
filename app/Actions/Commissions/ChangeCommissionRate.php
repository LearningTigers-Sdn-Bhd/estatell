<?php

namespace App\Actions\Commissions;

use App\Enums\CommissionService;
use App\Models\CommissionRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a new platform rate for a service from a start date. Past rows are never edited, so bookings keep the rate
 * of the day they were made. Returns null when the service already has a rate on that date.
 * See docs/30-commission-fees/02-commission-rules.md#rates.
 */
class ChangeCommissionRate
{
    public function __invoke(User $admin, CommissionService $service, string $rate, CarbonImmutable $effectiveFrom): ?CommissionRate
    {
        try {
            $commissionRate = DB::transaction(fn (): CommissionRate => CommissionRate::query()->create([
                'service' => $service,
                'rate' => $rate,
                'effective_from' => $effectiveFrom->toDateString(),
                'created_by' => $admin->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        activity()
            ->performedOn($commissionRate)
            ->causedBy($admin)
            ->event('created')
            ->withProperties(['service' => $service->value, 'rate' => $commissionRate->rate, 'effective_from' => $effectiveFrom->toDateString()])
            ->log('Commission rate changed');

        return $commissionRate;
    }
}
