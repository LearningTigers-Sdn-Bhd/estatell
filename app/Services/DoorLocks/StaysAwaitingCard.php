<?php

namespace App\Services\DoorLocks;

use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorCard;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * The stays the Concierge page checked in during the last `KioskStays::KEY_WINDOW_MINUTES` that still have no live card, oldest
 * first, and whose room has a TTLock lock with a gateway (cards are only added through a gateway). The Door locks page offers the
 * first one to the desk reader so the card on it is registered for that guest. Reads only.
 */
class StaysAwaitingCard
{
    public function __construct(private StayLocks $stayLocks) {}

    /**
     * @return Collection<int, ReservationUnit>
     */
    public function for(Property $property): Collection
    {
        $recent = Activity::query()
            ->where('event', 'concierge.checked_in')
            ->where('subject_type', (new ReservationUnit)->getMorphClass())
            ->where('created_at', '>=', now()->subMinutes(KioskStays::KEY_WINDOW_MINUTES))
            ->pluck('subject_id');

        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::CheckedIn)
            ->whereIn('id', $recent)
            ->whereNotIn('id', DoorCard::query()
                ->where('property_id', $property->id)
                ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active])
                ->whereNotNull('reservation_unit_id')
                ->select('reservation_unit_id'))
            ->with('reservation', 'property.settings')
            ->orderBy('id')
            ->get()
            ->filter(fn (ReservationUnit $unit): bool => $this->stayLocks->for($unit)->contains('has_gateway', true))
            ->values();
    }
}
