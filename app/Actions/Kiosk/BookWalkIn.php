<?php

namespace App\Actions\Kiosk;

use App\Actions\Folio\CreateFolio;
use App\Actions\Guests\CreateGuest;
use App\Actions\Reservations\BookingRefused;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Webhooks\TriggerWebhook;
use App\Data\Kiosk\KioskWalkInData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\BillingMode;
use App\Enums\StayType;
use App\Models\Guest;
use App\Models\Kiosk;
use App\Models\KioskWalkInHold;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\FrontDesk\SelfCheckInPayment;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Reservations\AutoAddOns;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class BookWalkIn
{
    public const int HOLD_MINUTES = 10;

    public function __construct(private KioskWalkIn $walkIn, private CreateGuest $createGuest, private CreateBooking $createBooking, private AutoAddOns $autoAddOns, private SelfCheckInPayment $payment, private CreateFolio $createFolio) {}

    public function __invoke(Kiosk $kiosk, KioskWalkInData $search, int $roomTypeId): KioskWalkInResult
    {
        if (! $kiosk->property->settings->kiosk_walk_in_enabled) {
            return new KioskWalkInResult(refusal: 'no_room_available');
        }

        return $this->book($kiosk->property, $kiosk, $search, $roomTypeId);
    }

    /**
     * Book and hold the room for any channel. `$kiosk` is the machine that asked, or null for the Concierge page; each
     * channel checks its own walk-in switch before it calls this. `$guest` is a guest of the property who is booking, such as a
     * returning guest the Concierge page recognised; without one the booking gets a guest with no name for the ID step to fill.
     */
    public function book(Property $property, ?Kiosk $kiosk, KioskWalkInData $search, int $roomTypeId, ?Guest $guest = null): KioskWalkInResult
    {
        try {
            return DB::transaction(function () use ($property, $kiosk, $search, $roomTypeId, $guest): KioskWalkInResult {
                $type = RoomType::query()->where('property_id', $property->id)->lockForUpdate()->find($roomTypeId);
                $offer = $type === null ? null : ($this->walkIn->available($property, $search, $roomTypeId)[0] ?? null);
                if ($offer === null || ! $offer->available) {
                    return new KioskWalkInResult(refusal: 'no_room_available');
                }
                $source = $this->walkIn->source($property);
                if ($source === null) {
                    return new KioskWalkInResult(refusal: 'server_error');
                }

                // Identity is collected after the hold. An empty name lets the ID step fill it without inventing guest data.
                $guest ??= ($this->createGuest)(null, $property, ['full_name' => '']);
                // `booking.created` waits until the hold is kept (`AnnounceWalkInBooking`), when the booker has a name.
                $booking = TriggerWebhook::muted(fn (): BookingResult => ($this->createBooking)(null, $property, new NewBookingData(
                    bookingSource: $source,
                    units: [$search->isDayUse()
                        ? new NewUnitData($offer->roomType->id, $offer->ratePlan->id, $search->arrival, $search->arrival, $search->adults, $search->childAges, stayType: StayType::DayUse, startTime: $search->startTime, hours: $search->hours, roomId: $offer->roomId)
                        : new NewUnitData($offer->roomType->id, $offer->ratePlan->id, $search->arrival, $search->arrival->addDays($search->nights), $search->adults, $search->childAges, addOns: $this->autoAddOns->forUnit($property))],
                    guests: [new NewBookingGuestData(guestId: $guest->id, unitIndex: 0)],
                    billingMode: BillingMode::EachPays,
                )));
                if ($booking->isRefused()) {
                    throw new BookingRefused($booking);
                }
                $stay = $booking->reservation->units()->sole();
                ($this->createFolio)($booking->reservation, $stay);
                $hold = KioskWalkInHold::query()->create([
                    'property_id' => $property->id, 'reservation_unit_id' => $stay->id,
                    'kiosk_id' => $kiosk?->id, 'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                ]);
                activity()->performedOn($stay)->event($kiosk === null ? 'concierge.walk_in_booked' : 'kiosk.walk_in_booked')
                    ->withProperties($kiosk === null ? ['property_id' => $property->id] : ['property_id' => $property->id, 'kiosk_id' => $kiosk->id])
                    ->log($kiosk === null ? 'Walk-in held on the concierge page' : 'Walk-in held at the kiosk');

                return new KioskWalkInResult($hold, Money::of($this->payment->shortfall($stay), $property->settings->currency));
            });
        } catch (BookingRefused) {
            return new KioskWalkInResult(refusal: 'no_room_available');
        }
    }
}
