<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergeRequestData;
use App\Models\GuestRequest;
use App\Models\ReservationUnit;
use Illuminate\Support\Collection;

/**
 * The requests a stay has made, newest first. It reads only.
 */
class ConciergeRequests
{
    public const int LIMIT = 50;

    /**
     * @return Collection<int, ConciergeRequestData>
     */
    public function forStay(ReservationUnit $stay): Collection
    {
        return GuestRequest::query()
            ->where('property_id', $stay->property_id)
            ->where('reservation_unit_id', $stay->id)
            ->orderByDesc('opened_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (GuestRequest $request): ConciergeRequestData => ConciergeRequestData::fromModel($request));
    }
}
