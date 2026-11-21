<?php

namespace App\Actions\Reservations;

use App\Models\MarketSegment;
use App\Models\User;
use App\Services\Reservations\MarketSegments;
use Illuminate\Support\Facades\DB;

class UpdateMarketSegment
{
    public function __construct(private MarketSegments $segments) {}

    /**
     * Rename a segment or turn it on or off. A segment turned off leaves the picker but stays on the bookings that have
     * it. Returns false, without writing or logging, when another segment has the name ignoring case. Logs
     * `market_segment.updated` with the changed fields, and nothing when nothing changed.
     */
    public function __invoke(User $actor, MarketSegment $segment, string $name, bool $active): bool
    {
        return DB::transaction(function () use ($actor, $segment, $name, $active): bool {
            $segment = MarketSegment::query()->lockForUpdate()->findOrFail($segment->id);

            if ($this->segments->nameTaken($segment->property_id, $name, $segment->id)) {
                return false;
            }

            $segment->fill(['name' => $name, 'active' => $active]);
            $changes = array_keys($segment->getDirty());

            if ($changes === []) {
                return true;
            }

            $segment->save();

            activity()
                ->performedOn($segment)
                ->causedBy($actor)
                ->event('market_segment.updated')
                ->withProperties(['property_id' => $segment->property_id, 'changed' => $changes])
                ->log('Market segment updated');

            return true;
        });
    }
}
