<?php

namespace App\Actions\BookingEngine;

use App\Data\BookingEngine\NewBookingHoldData;
use App\Data\BookingEngine\NewBookingHoldLineData;
use App\Enums\BookingHoldStatus;
use App\Enums\SellUnit;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\BookingEngine\BookingBasket;
use App\Services\BookingEngine\BookingHoldLimits;
use App\Services\BookingEngine\BookingOffer;
use App\Services\BookingEngine\BookingWindow;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keep rooms for a guest for 20 minutes while they pay. The server decides availability and price again, under the room
 * type lock, so a hold and a staff booking cannot sell the same room twice. See docs/29-booking-engine/03-checkout-and-hold.md.
 */
class CreateBookingHold
{
    /** A staff booking locks its room types in line order, so a hold can deadlock with it. The retry hides that from the guest. */
    private const int ATTEMPTS = 3;

    public function __construct(
        private BookingWindow $window,
        private BookingBasket $basket,
        private BookingHoldLimits $limits,
        private AnnounceBookingHoldRooms $announce,
    ) {}

    public function __invoke(BookingClient $client, Property $property, NewBookingHoldData $data): BookingHoldResult
    {
        $guestIpHash = $this->guestIpHash($data->guestIp);

        if ($guestIpHash === null && $client->type->requiresGuestIp()) {
            return BookingHoldResult::refused(BookingHoldResult::REFUSAL_GUEST_IP_REQUIRED, __('This request needs the guest IP address.'));
        }

        if (! $this->window->contains($property, $data->arrival)) {
            return BookingHoldResult::refused(BookingHoldResult::REFUSAL_OUTSIDE_WINDOW, __('Choose an arrival from today to :months months ahead.', ['months' => BookingWindow::MONTHS_AHEAD]));
        }

        return DB::transaction(fn (): BookingHoldResult => $this->hold($client, $property, $data, $guestIpHash), self::ATTEMPTS);
    }

    private function hold(BookingClient $client, Property $property, NewBookingHoldData $data, ?string $guestIpHash): BookingHoldResult
    {
        RoomType::query()
            ->where('property_id', $property->id)
            ->whereIn('id', array_map(fn (NewBookingHoldLineData $line): int => $line->roomTypeId, $data->lines))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $price = $this->basket->price($property, $data->arrival, $data->departure, $data->lines, $data->guestNationality);

        if ($price->refusal !== null) {
            return BookingHoldResult::refused($price->refusal, (string) $price->message);
        }

        /** @var list<BookingOffer> $offers The basket found no empty line. */
        $offers = $price->offers;
        $total = $price->onlineTotal;
        $deskTotal = $price->deskTotal;

        if (! $this->limits->propertyAllows($property, count($offers)) || ($guestIpHash !== null && ! $this->limits->guestIpAllows($property, $guestIpHash))) {
            return BookingHoldResult::refused(BookingHoldResult::REFUSAL_HOLD_LIMIT_REACHED, __('Too many rooms are being booked at the moment. Try again in a few minutes.'));
        }

        if (! BigDecimal::of($data->total)->isEqualTo($total->getAmount())) {
            return BookingHoldResult::refused(BookingHoldResult::REFUSAL_PRICE_CHANGED, __('The price has changed. Check the new total.'), (string) $total->getAmount());
        }

        $hold = BookingHold::query()->create([
            'property_id' => $property->id,
            'booking_client_id' => $client->id,
            'token' => Str::random(64),
            'status' => BookingHoldStatus::Held,
            'arrival_date' => $data->arrival,
            'departure_date' => $data->departure,
            'total_amount' => (string) $total->getAmount(),
            'expires_at' => now()->addMinutes(BookingHold::MINUTES),
            'guest_name' => $data->guestName,
            'guest_email' => $data->guestEmail,
            'guest_phone' => $data->guestPhone,
            'guest_nationality' => $data->guestNationality,
            'special_requests' => $data->specialRequests,
            'guest_ip_hash' => $guestIpHash,
        ]);

        foreach ($data->lines as $position => $line) {
            $this->addLine($hold, $line, $offers[$position], $position);
        }

        ($this->announce)($hold);

        return BookingHoldResult::held($hold, $deskTotal);
    }

    private function addLine(BookingHold $hold, NewBookingHoldLineData $line, BookingOffer $offer, int $position): void
    {
        $isBed = $offer->roomType->sell_unit === SellUnit::Bed;
        $saved = $hold->lines()->create([
            'room_type_id' => $line->roomTypeId,
            'rate_plan_id' => $line->ratePlanId,
            'adults' => $isBed ? 1 : $line->adults,
            'amount' => (string) $offer->price->onlineTotal->getAmount(),
            'room_total' => (string) $offer->roomTotal?->getAmount(),
            'position' => $position,
        ]);

        if (! $isBed) {
            $saved->children()->createMany(array_map(fn (int $age): array => ['age' => $age], $line->childAges));
        }
    }

    /**
     * The address itself is not stored. A missing or malformed header counts as no address.
     */
    private function guestIpHash(?string $guestIp): ?string
    {
        if ($guestIp === null || filter_var($guestIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return hash_hmac('sha256', $guestIp, (string) config('app.key'));
    }
}
