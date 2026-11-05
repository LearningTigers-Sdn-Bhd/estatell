<?php

namespace App\Actions\Boats;

use App\Models\Boat;
use App\Models\BoatDeparture;
use App\Models\BoatDepartureRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SetBoatDepartureRun
{
    /**
     * Save the boat and crew of one departure on one date for the manifest. Both empty or $resetToDefault removes the row.
     * The boat must be an active boat of the departure's property; returns false otherwise and writes nothing.
     * Logs `boat_departure_run.set` when something changed.
     */
    public function __invoke(User $actor, BoatDeparture $departure, CarbonImmutable $date, ?int $boatId, ?string $crew, bool $resetToDefault = false): bool
    {
        $crew = filled($crew) ? mb_substr(trim((string) $crew), 0, 120) : null;

        if (! $resetToDefault && $boatId !== null && ! Boat::query()->where('property_id', $departure->property_id)->where('active', true)->whereKey($boatId)->exists()) {
            return false;
        }

        return DB::transaction(function () use ($actor, $departure, $date, $boatId, $crew, $resetToDefault): bool {
            $run = BoatDepartureRun::query()
                ->where('boat_departure_id', $departure->id)
                ->whereDate('date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if ($resetToDefault || ($boatId === null && $crew === null)) {
                if ($run !== null) {
                    $run->delete();
                    activity()
                        ->performedOn($departure)
                        ->causedBy($actor)
                        ->event('boat_departure_run.set')
                        ->withProperties(['property_id' => $departure->property_id, 'date' => $date->toDateString(), 'boat_id' => null, 'crew' => null])
                        ->log('Boat and crew reset');
                }
            } else {
                $run ??= new BoatDepartureRun;
                $run->forceFill(['property_id' => $departure->property_id, 'boat_departure_id' => $departure->id, 'date' => $date->toDateString()]);
                $run->fill(['boat_id' => $boatId, 'crew' => $crew]);

                if (! $run->isDirty()) {
                    return true;
                }

                $run->save();

                activity()
                    ->performedOn($departure)
                    ->causedBy($actor)
                    ->event('boat_departure_run.set')
                    ->withProperties(['property_id' => $departure->property_id, 'date' => $date->toDateString(), 'boat_id' => $boatId, 'crew' => $crew])
                    ->log('Boat and crew set');
            }

            return true;
        });
    }
}
