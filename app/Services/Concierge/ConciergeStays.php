<?php

namespace App\Services\Concierge;

use App\Enums\UnitStatus;
use App\Models\BookingPreCheckInToken;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;
use Spatie\Activitylog\Models\Activity;

/**
 * Finds the stay a guest opens on the Concierge page. A stay comes from its pre-check-in link (`BookingPreCheckInToken`,
 * one per stay, valid until the day after departure) or from the booking code alone. A code that matches more than one
 * room of a booking opens none, so the guest uses the link of each room. Reads only.
 */
class ConciergeStays
{
    /**
     * @param  bool  $forReading  A page that only reads may open a cancelled stay (a walk-in whose unpaid hold was released
     *                            is told so) and a checked-out stay (the guest sees the thank-you). Writes never do.
     */
    public function byToken(string $token, bool $forReading = false): ?ReservationUnit
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }

        $row = BookingPreCheckInToken::query()->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();

        return $row === null ? null : ReservationUnit::query()
            ->where('property_id', $row->property_id)
            ->whereIn('status', $forReading ? [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut, UnitStatus::Cancelled] : [UnitStatus::Confirmed, UnitStatus::CheckedIn])
            ->find($row->reservation_unit_id);
    }

    public function byReference(Property $property, string $reference): ?ReservationUnit
    {
        $matches = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])
            ->whereHas('reservation', fn ($query) => $query
                ->where('property_id', $property->id)
                ->whereRaw('lower(reference) = ?', [mb_strtolower(trim($reference))]))
            ->orderBy('arrival_date')->orderBy('id')->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Whether the Concierge page checked this stay in during the last `KioskStays::KEY_WINDOW_MINUTES`. Only then does
     * the page show the room and the PIN, so a link found later cannot read the key of a guest who has settled in.
     */
    public function checkedInByConcierge(ReservationUnit $stay): bool
    {
        return Activity::query()
            ->where('event', 'concierge.checked_in')
            ->where('created_at', '>=', now()->subMinutes(KioskStays::KEY_WINDOW_MINUTES))
            ->forSubject($stay)
            ->exists();
    }
}
