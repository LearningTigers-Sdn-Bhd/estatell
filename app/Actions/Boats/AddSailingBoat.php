<?php

namespace App\Actions\Boats;

use App\Data\Boats\SailingData;
use App\Enums\SailingKind;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use App\Services\Boats\SailingChoices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AddSailingBoat
{
    public function __construct(
        private SailingChoices $choices,
        private BoatTripUsage $boatTripUsage,
        private StoreMainSailing $storeMainSailing,
        private SyncBoatSailingCrew $syncCrew,
    ) {}

    /**
     * Add another boat to a departure on a date. The date's main sailing is stored first when it has no rows, so the new
     * sailing is never the main one. The boat is required. Its crew starts empty unless the data names one. Locks the
     * departure and its sailings of the date, and logs `boat_sailing.boat_added`. See docs/24-boats/04-sailings.md.
     */
    public function __invoke(User $actor, BoatDeparture $departure, CarbonImmutable $date, SailingData $data): SailingResult
    {
        $property = Property::query()->findOrFail($departure->property_id);

        if ($date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        $assistants = $data->assistants();
        $refusal = $data->boatId === null
            ? SailingResult::REFUSAL_BOAT_UNAVAILABLE
            : $this->choices->refusal($property->id, $data->boatId, $data->inChargeId, $assistants);

        if ($refusal !== null) {
            return SailingResult::refused($refusal);
        }

        return DB::transaction(function () use ($actor, $property, $departure, $date, $data, $assistants): SailingResult {
            $locked = BoatDeparture::query()->where('property_id', $property->id)->lockForUpdate()->findOrFail($departure->id);
            $exists = BoatSailing::query()
                ->where('boat_departure_id', $locked->id)
                ->whereDate('date', $date->toDateString())
                ->where('kind', SailingKind::Guests->value)
                ->lockForUpdate()
                ->exists();

            if (! $exists) {
                ($this->storeMainSailing)($locked, $date);
            }

            $sailing = BoatSailing::query()->create([
                'property_id' => $property->id,
                'date' => $date->toDateString(),
                'kind' => SailingKind::Guests,
                'direction' => $locked->direction->value,
                'boat_departure_id' => $locked->id,
                'boat_id' => $data->boatId,
                'leaves_at' => $data->setsTimes && $data->leavesAt !== null ? substr($data->leavesAt, 0, 5).':00' : null,
                'arrives_at' => $data->setsTimes && $data->arrivesAt !== null ? substr($data->arrivesAt, 0, 5).':00' : null,
            ]);

            ($this->syncCrew)($sailing, $data->inChargeId, $assistants);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_sailing.boat_added')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $sailing->id,
                    'boat_departure_id' => $locked->id,
                    'date' => $date->toDateString(),
                    'boat_id' => $data->boatId,
                    'in_charge' => $data->inChargeId,
                    'assistants' => $assistants,
                ])
                ->log('Boat added to a departure');

            return SailingResult::done($sailing);
        });
    }
}
