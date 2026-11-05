<?php

namespace App\Actions\Kiosk;

use App\Actions\Folio\CreateFolio;
use App\Actions\Guests\CreateGuest;
use App\Actions\Reservations\BookingRefused;
use App\Actions\Reservations\CreateBooking;
use App\Data\Kiosk\KioskWalkInData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\BillingMode;
use App\Models\Kiosk;
use App\Models\KioskWalkInHold;
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
        try {
            return DB::transaction(function () use ($kiosk, $search, $roomTypeId): KioskWalkInResult {
                $property = $kiosk->property;
                $type = RoomType::query()->where('property_id', $property->id)->lockForUpdate()->find($roomTypeId);
                $offer = $type === null ? null : ($this->walkIn->offers($property, $search, $roomTypeId)[0] ?? null);
                if ($offer === null || ! $offer->available) {
                    return new KioskWalkInResult(refusal: 'no_room_available');
                }
                $source = $this->walkIn->source($property);
                if ($source === null) {
                    return new KioskWalkInResult(refusal: 'server_error');
                }

                // Identity is collected after the hold. An empty name lets the ID step fill it without inventing guest data.
                $guest = ($this->createGuest)(null, $property, ['full_name' => '']);
                $booking = ($this->createBooking)(null, $property, new NewBookingData(
                    bookingSource: $source,
                    units: [new NewUnitData($offer->roomType->id, $offer->ratePlan->id, $search->arrival, $search->arrival->addDays($search->nights), $search->adults, $search->childAges, addOns: $this->autoAddOns->forUnit($property))],
                    guests: [new NewBookingGuestData(guestId: $guest->id, unitIndex: 0)],
                    billingMode: BillingMode::EachPays,
                ));
                if ($booking->isRefused()) {
                    throw new BookingRefused($booking);
                }
                $stay = $booking->reservation->units()->sole();
                ($this->createFolio)($booking->reservation, $stay);
                $hold = KioskWalkInHold::query()->create([
                    'property_id' => $property->id, 'reservation_unit_id' => $stay->id,
                    'kiosk_id' => $kiosk->id, 'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                ]);
                activity()->performedOn($stay)->event('kiosk.walk_in_booked')
                    ->withProperties(['property_id' => $property->id, 'kiosk_id' => $kiosk->id])->log('Walk-in held at the kiosk');

                return new KioskWalkInResult($hold, Money::of($this->payment->shortfall($stay), $property->settings->currency));
            });
        } catch (BookingRefused) {
            return new KioskWalkInResult(refusal: 'no_room_available');
        }
    }
}
