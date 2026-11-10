<?php

namespace App\Actions\Boats;

use App\Enums\SailingKind;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use Carbon\CarbonImmutable;

class StoreMainSailing
{
    public function __construct(private SyncBoatSailingCrew $syncCrew) {}

    /**
     * Store the main sailing of a departure on a date, copying the departure's default boat and crew. The caller holds
     * the departure lock and has checked that the date has no sailing of the departure yet. See
     * docs/24-boats/04-sailings.md#main-sailing.
     */
    public function __invoke(BoatDeparture $departure, CarbonImmutable $date): BoatSailing
    {
        $sailing = BoatSailing::query()->create([
            'property_id' => $departure->property_id,
            'date' => $date->toDateString(),
            'kind' => SailingKind::Guests,
            'direction' => $departure->direction->value,
            'boat_departure_id' => $departure->id,
            'boat_id' => $departure->default_boat_id,
        ]);

        $crew = $departure->crew()->get();

        ($this->syncCrew)(
            $sailing,
            $crew->first(fn ($member): bool => (bool) $member->pivot->in_charge)?->id,
            array_values($crew->reject(fn ($member): bool => (bool) $member->pivot->in_charge)->map(fn ($member): int => $member->id)->all()),
        );

        return $sailing;
    }
}
