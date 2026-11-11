<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingHoldStatus;
use App\Models\BookingHold;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * Keep a hold's rooms now that its order is paid. An open hold becomes Paid. A hold past its expiry (a bank can confirm
 * late) becomes Paid only if its rooms are still free, checked under the room type lock, and it took those rooms back, so
 * the channels are told. Returns false when the rooms are taken: the hold stays as it is, because a Paid hold counts in
 * availability. A hold already Paid or Confirmed returns true. See docs/29-booking-engine/04-payment.md#paid-after-the-hold-expired.
 */
class MarkBookingHoldPaid
{
    /** A staff booking locks its room types in line order, so this can deadlock with it. */
    private const int ATTEMPTS = 3;

    public function __construct(private AvailabilityService $availability, private AnnounceBookingHoldRooms $announce) {}

    public function __invoke(BookingHold $hold): bool
    {
        return DB::transaction(function () use ($hold): bool {
            $needed = $hold->lines()->reorder()->pluck('room_type_id')->countBy();
            $roomTypes = RoomType::query()
                ->where('property_id', $hold->property_id)
                ->whereIn('id', $needed->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = BookingHold::query()->lockForUpdate()->findOrFail($hold->id);

            if (in_array($locked->status, [BookingHoldStatus::Paid, BookingHoldStatus::Confirmed], true)) {
                return true;
            }

            $isLate = $locked->status === BookingHoldStatus::Expired || ! $locked->expires_at->isFuture();

            if ($isLate && $roomTypes->contains(fn (RoomType $roomType): bool => $this->availability->availableForStay($roomType, $locked->arrival_date, $locked->departure_date) < $needed[$roomType->id])) {
                return false;
            }

            $locked->update(['status' => BookingHoldStatus::Paid]);

            if ($isLate) {
                ($this->announce)($locked);
            }

            return true;
        }, self::ATTEMPTS);
    }
}
