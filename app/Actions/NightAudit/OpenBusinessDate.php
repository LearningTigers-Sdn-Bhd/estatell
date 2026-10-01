<?php

namespace App\Actions\NightAudit;

use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class OpenBusinessDate
{
    /**
     * Open the first business date of a property on the chosen date.
     *
     * Returns null when the property already has a business date. Later dates are opened by the night audit, never by hand.
     */
    public function __invoke(Property $property, CarbonInterface $date, ?User $actor = null): ?BusinessDate
    {
        return DB::transaction(function () use ($property, $date, $actor): ?BusinessDate {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->businessDates()->exists()) {
                return null;
            }

            $businessDate = $property->businessDates()->create([
                'date' => $date->toDateString(),
                'status' => BusinessDateStatus::Open,
                'opened_at' => now(),
            ]);

            activity()
                ->performedOn($businessDate)
                ->causedBy($actor)
                ->event('business-date.opened')
                ->withProperties(['date' => $businessDate->date->toDateString()])
                ->log('Business date opened');

            return $businessDate;
        });
    }
}
