<?php

namespace App\Services\Kiosk;

use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\BookingPreCheckInToken;
use App\Models\Guest;
use App\Models\Kiosk;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Services\FrontDesk\StayDay;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Finds the stay a kiosk works on and the guest who checks in. A stay is a reservation unit that is still Confirmed
 * and inside its check-in window (`window()`). Every query is scoped to the token's property, so a stay of another
 * property is the same as a missing one. Reads only.
 */
class KioskStays
{
    /** How long after the kiosk check-in the guest can still get the room and key at the kiosk. */
    public const int KEY_WINDOW_MINUTES = 30;

    public const string OPEN = 'open';

    public const string EARLY = 'early';

    public const string ENDED = 'ended';

    /** The property has no open business date yet. */
    public const string NOT_LIVE = 'not_live';

    public function __construct(private CurrentBusinessDate $currentBusinessDate, private StayDay $stayDay, private KioskWalkInHolds $holds) {}

    /**
     * The one check-in window rule of the kiosk, the same as `CheckInUnit`: the stay day (`StayDay`, not the calendar
     * date) is on or after the arrival date and before the departure date (a day use stay: on its day).
     *
     * @return self::OPEN|self::EARLY|self::ENDED|self::NOT_LIVE
     */
    public function window(ReservationUnit $stay): string
    {
        $businessDate = $this->currentBusinessDate->date($stay->property()->firstOrFail());

        if ($businessDate === null) {
            return self::NOT_LIVE;
        }

        $day = $this->stayDay->forCheckIn($stay, $businessDate)->toDateString();
        $arrival = $stay->arrival_date->toDateString();
        $ended = $stay->stay_type === StayType::DayUse ? $day > $arrival : $day >= $stay->departure_date->toDateString();

        return match (true) {
            $ended => self::ENDED,
            $day < $arrival => self::EARLY,
            default => self::OPEN,
        };
    }

    /**
     * @param  list<UnitStatus>  $statuses  The statuses the caller can work on (default: Confirmed, before check-in).
     * @return ReservationUnit|null The stay with that id in the property, only while its window is open. A stay
     *                              outside the window or in another status is the same as a missing one.
     */
    public function find(Property $property, int $id, array $statuses = [UnitStatus::Confirmed], bool $checkOut = false): ?ReservationUnit
    {
        $stay = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', $statuses)
            ->find($id);

        return $stay !== null && ! $this->holds->expired($property, $stay->id) && ($checkOut ? $this->checkOutWindow($stay) : $this->window($stay) === self::OPEN) ? $stay : null;
    }

    public function expiredHold(Property $property, int $id): bool
    {
        return $this->holds->expired($property, $id);
    }

    public function checkOutWindow(ReservationUnit $stay): bool
    {
        $property = $stay->property()->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null) {
            return false;
        }

        $day = $stay->stay_type === StayType::DayUse ? $businessDate : $this->stayDay->forCheckOut($property, $businessDate);

        return $day->toDateString() >= $stay->departure_date->toDateString();
    }

    public function checkedOutByKiosk(ReservationUnit $stay, Kiosk $kiosk): bool
    {
        return Activity::query()->forSubject($stay)->where('event', 'kiosk.checked_out')
            ->where('properties->kiosk_id', $kiosk->id)
            ->where('created_at', '>=', now()->subMinutes(self::KEY_WINDOW_MINUTES))->exists();
    }

    /**
     * Whether a kiosk checked this stay in during the last `KEY_WINDOW_MINUTES` (`CheckInStay` logs `kiosk.checked_in`).
     * The room and the key of a stay that is already in-house are only served for that short time after the kiosk
     * check-in, so a token that guesses ids cannot read the room or PIN of a guest who has long left the kiosk.
     */
    public function checkedInByKiosk(ReservationUnit $stay): bool
    {
        return Activity::query()
            ->where('event', 'kiosk.checked_in')
            ->where('created_at', '>=', now()->subMinutes(self::KEY_WINDOW_MINUTES))
            ->forSubject($stay)
            ->exists();
    }

    /** @return Collection<int, ReservationUnit> */
    public function byReference(Property $property, string $reference): Collection
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])
            ->whereHas('reservation', fn ($query) => $query
                ->where('property_id', $property->id)
                ->whereRaw('lower(reference) = ?', [mb_strtolower(trim($reference))]))
            ->orderBy('arrival_date')->orderBy('id')->limit(2)->get();
    }

    public function byQrToken(Property $property, string $value): ?ReservationUnit
    {
        if (preg_match('~^(?:https?://[^/?#]+)?/c/([A-Za-z0-9_-]{43})$~D', $value, $match) === 1) {
            $value = $match[1];
        }

        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
            return null;
        }

        $token = BookingPreCheckInToken::query()->where('property_id', $property->id)
            ->where('token_hash', hash('sha256', $value))->where('expires_at', '>', now())->first();

        return $token === null ? null : ReservationUnit::query()->where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])->find($token->reservation_unit_id);
    }

    /**
     * The primary guest of the stay, or the booker when it has none (the same person `UnitNationality` reads). Null
     * when that guest was merged into another guest or anonymized.
     */
    public function guest(ReservationUnit $unit): ?Guest
    {
        $primary = $unit->guests()->where('is_primary', true)->with('guest')->first();
        $guest = $primary === null ? $unit->reservation()->firstOrFail()->booker()->first() : $primary->guest;

        return $guest !== null && $guest->merged_into_id === null && $guest->anonymized_at === null ? $guest : null;
    }

    /**
     * The primary guest row of the stay: the one who signs the registration card. Null when the stay has none (the
     * booker is then the guest, and no card is needed).
     */
    public function primaryUnitGuest(ReservationUnit $unit): ?UnitGuest
    {
        return $unit->guests()->where('is_primary', true)->first();
    }

    /**
     * The number of the room the stay sleeps in on its check-in day (a bed counts as its room). Null when none is assigned.
     */
    public function roomNumber(ReservationUnit $unit, bool $checkOut = false): ?string
    {
        if ($unit->stay_type === StayType::DayUse) {
            return $unit->dayUse()->with('room')->first()?->room?->number;
        }

        $businessDate = $this->currentBusinessDate->date($unit->property()->firstOrFail());
        $night = $unit->nights()
            ->with(['room', 'bed.room'])
            ->where(fn ($query) => $query->whereNotNull('room_id')->orWhereNotNull('bed_id'))
            ->when($businessDate !== null, fn ($query) => $checkOut
                ? $query->whereDate('date', '<=', $this->stayDay->forCheckOut($unit->property, $businessDate)->toDateString())
                : $query->whereDate('date', $this->stayDay->forCheckIn($unit, $businessDate)->toDateString()))
            ->orderBy('date', $checkOut ? 'desc' : 'asc')
            ->first();

        if ($night === null) {
            return null;
        }

        return ($night->room_id === null ? $night->bed->room : $night->room)->number;
    }
}
