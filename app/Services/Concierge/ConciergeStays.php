<?php

namespace App\Services\Concierge;

use App\Enums\IdentityDocumentType;
use App\Enums\UnitStatus;
use App\Models\BookingPreCheckInToken;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Guests\GuestName;
use App\Services\Kiosk\KioskStays;
use App\Services\Kiosk\KioskWalkInHolds;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * Finds the stay a guest opens on the Concierge page. A stay comes from its pre-check-in link (`BookingPreCheckInToken`,
 * one per stay, valid until the day after departure) or from the booking code or the guest's name. A code that matches more than one
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

    public function byShortCode(Property $property, string $code): ?ReservationUnit
    {
        $matches = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::Confirmed, UnitStatus::CheckedIn])
            ->whereHas('reservation', fn ($query) => $query
                ->where('property_id', $property->id)
                ->where('short_code', mb_strtoupper(trim($code))))
            ->orderBy('arrival_date')->orderBy('id')->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The stay a guest opens by the name on the booking: an OTA booking reaches the property with no email or phone, so a
     * guest at the door may have no booking code. A name is easy to know, so it opens less than a code: only a stay that
     * can check in today and whose guest has no ID document yet (the guest enters it next). A checked-in stay, a
     * one-word name and a name that matches more than one stay open nothing.
     */
    public function byGuestName(Property $property, string $name): ?ReservationUnit
    {
        $key = app(GuestName::class)->key($name);

        if (! str_contains($key, ' ')) {
            return null;
        }

        $kiosk = app(KioskStays::class);
        $holds = app(KioskWalkInHolds::class);
        $named = fn ($query) => $query->where('name_key', $key);

        $matches = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::Confirmed)
            ->where(fn ($query) => $query
                ->whereHas('guests', fn ($guests) => $guests->where('is_primary', true)->whereHas('guest', $named))
                ->orWhereHas('reservation.booker', $named))
            ->get()
            ->filter(fn (ReservationUnit $stay): bool => $kiosk->guest($stay)?->name_key === $key
                && ! $holds->expired($property, $stay->id)
                && $kiosk->window($stay) === KioskStays::OPEN);

        $stay = $matches->count() === 1 ? $matches->first() : null;

        return $stay === null || $kiosk->guest($stay)->documents()->exists() ? null : $stay;
    }

    /** Whether an ID document has its photos: the front, and for a MyKad the back too. */
    public function hasPhotos(GuestIdentityDocument $document): bool
    {
        return $document->hasMedia(GuestIdentityDocument::FRONT_SCAN)
            && ($document->type !== IdentityDocumentType::MyKad || $document->hasMedia(GuestIdentityDocument::BACK_SCAN));
    }

    /** Whether the document a blank number keeps (the latest of that type, as `SaveConciergeIdentity` picks it) has its photos. */
    public function keptDocumentHasPhoto(Guest $guest, IdentityDocumentType $type): bool
    {
        $document = $guest->documents()->where('type', $type)->latest('id')->first();

        return $document !== null && $this->hasPhotos($document);
    }

    /** Whether the property requires an ID photo and the stay's guest has none yet. */
    public function idPhotoMissing(ReservationUnit $stay): bool
    {
        $stay->loadMissing('property.settings');

        if (! $stay->property->settings?->concierge_name_check_in_enabled) {
            return false;
        }

        $guest = app(KioskStays::class)->guest($stay);

        return $guest === null || ! $guest->documents()->get()->contains(fn (GuestIdentityDocument $document): bool => $this->hasPhotos($document));
    }

    /**
     * Why a booking code opened no stay, so the guest is told what to do next. `not_found` covers a wrong code and a
     * code of another property alike. The others only name a booking that exists at this property, and the lookup
     * lockout still counts the try.
     *
     * @return 'multiple_rooms'|'checked_out'|'closed'|'not_found'
     */
    public function lookupFailure(Property $property, string $code): string
    {
        $statuses = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereHas('reservation', fn ($query) => $query
                ->where('property_id', $property->id)
                ->where('short_code', mb_strtoupper(trim($code))))
            ->pluck('status');

        return match (true) {
            $statuses->isEmpty() => 'not_found',
            $statuses->filter(fn (UnitStatus $status) => in_array($status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true))->count() > 1 => 'multiple_rooms',
            $statuses->contains(UnitStatus::CheckedOut) => 'checked_out',
            default => 'closed',
        };
    }

    /**
     * When check-in opens: the arrival day at the property's check-in time, in its time zone. Null when the property sets
     * no check-in time. A guest may register and pay before this; only the check-in itself waits for it.
     */
    public function checkInOpensAt(ReservationUnit $stay): ?CarbonImmutable
    {
        $stay->loadMissing('property.settings');
        $settings = $stay->property->settings;

        return blank($settings->check_in_time)
            ? null
            : CarbonImmutable::parse($stay->arrival_date->toDateString().' '.$settings->check_in_time, $settings->time_zone);
    }

    public function checkInTimeReached(ReservationUnit $stay): bool
    {
        $opensAt = $this->checkInOpensAt($stay);

        return $opensAt === null || now()->greaterThanOrEqualTo($opensAt);
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
