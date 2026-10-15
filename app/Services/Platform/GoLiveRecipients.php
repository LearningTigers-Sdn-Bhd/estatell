<?php

namespace App\Services\Platform;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who hears about a new go-live request: every active platform admin, and the active partner users of the property's
 * partner. Reads only.
 */
class GoLiveRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function forRequest(Property $property): Collection
    {
        return User::query()
            ->whereNull('deactivated_at')
            ->where(fn ($query) => $query
                ->where('is_admin', true)
                ->when($property->partner_id !== null, fn ($query) => $query->orWhere('partner_id', $property->partner_id)))
            ->get();
    }
}
