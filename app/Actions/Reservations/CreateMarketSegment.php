<?php

namespace App\Actions\Reservations;

use App\Models\MarketSegment;
use App\Models\Property;
use App\Models\User;
use App\Services\Reservations\MarketSegments;
use Illuminate\Support\Facades\DB;

class CreateMarketSegment
{
    public function __construct(private MarketSegments $segments) {}

    /**
     * Add an active segment. Returns null, without writing or logging, when another segment of the property has the
     * name ignoring case (a race the request did not catch). Logs `market_segment.created`.
     */
    public function __invoke(Property $property, User $actor, string $name): ?MarketSegment
    {
        return DB::transaction(function () use ($property, $actor, $name): ?MarketSegment {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($this->segments->nameTaken($property->id, $name)) {
                return null;
            }

            $segment = new MarketSegment(['name' => $name, 'active' => true]);
            $segment->property_id = $property->id;
            $segment->save();

            activity()
                ->performedOn($segment)
                ->causedBy($actor)
                ->event('market_segment.created')
                ->withProperties(['property_id' => $property->id, 'name' => $segment->name])
                ->log('Market segment created');

            return $segment;
        });
    }
}
