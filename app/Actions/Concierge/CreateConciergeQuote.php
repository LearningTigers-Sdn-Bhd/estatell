<?php

namespace App\Actions\Concierge;

use App\Data\Kiosk\KioskWalkInData;
use App\Models\ConciergeQuote;
use App\Models\Property;
use App\Services\BookingEngine\BookingWindow;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Kiosk\KioskWalkInOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Save a quote link for one room type and plan of a future stay. Nothing is booked or held: the guest books on the quote
 * page. The price is the one the booking will charge (`KioskWalkIn::quotable`), and the link lasts `VALID_HOURS`. Refused,
 * writing nothing, when the dates are wrong, the arrival is outside `BookingWindow`, or the room is not free on that plan.
 * See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class CreateConciergeQuote
{
    public const int VALID_HOURS = 24;

    public function __construct(private KioskWalkIn $walkIn, private BookingWindow $window) {}

    /**
     * @param  list<int>  $childAges
     */
    public function __invoke(Property $property, int $roomTypeId, int $ratePlanId, CarbonImmutable $arrival, CarbonImmutable $departure, int $adults, array $childAges = []): ConciergeQuoteCreated
    {
        $arrival = $arrival->startOfDay();
        $departure = $departure->startOfDay();

        if ($adults < 1 || ! $departure->isAfter($arrival)) {
            return ConciergeQuoteCreated::refused('invalid_dates');
        }

        $property->loadMissing('settings');

        if (! $this->window->contains($property, $arrival)) {
            return ConciergeQuoteCreated::refused('outside_window');
        }

        $search = new KioskWalkInData($arrival, (int) $arrival->diffInDays($departure), $adults, $childAges);
        $offer = array_find($this->walkIn->quotable($property, $search, $roomTypeId, $ratePlanId), fn (KioskWalkInOffer $offer): bool => $offer->available);

        if ($offer === null) {
            return ConciergeQuoteCreated::refused('no_room_available');
        }

        return ConciergeQuoteCreated::created(DB::transaction(function () use ($property, $offer, $arrival, $departure, $adults, $childAges): ConciergeQuote {
            $quote = ConciergeQuote::query()->create([
                'property_id' => $property->id,
                'token' => Str::random(40),
                'room_type_id' => $offer->roomType->id,
                'rate_plan_id' => $offer->ratePlan->id,
                'arrival_date' => $arrival,
                'departure_date' => $departure,
                'adults' => $adults,
                'quoted_total' => (string) $offer->total->getAmount(),
                'expires_at' => now()->addHours(self::VALID_HOURS),
            ]);
            $quote->children()->createMany(array_map(fn (int $age): array => ['age' => $age], $childAges));

            return $quote;
        }));
    }
}
