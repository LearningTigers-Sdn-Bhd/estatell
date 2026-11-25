<?php

namespace App\Ai\Tools;

use App\Data\Kiosk\KioskWalkInData;
use App\Models\Property;
use App\Services\BookingEngine\BookingWindow;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Kiosk\KioskWalkInOffer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The rooms free for a stay and their prices, one per room type and plan sold online, priced as the booking will charge.
 * The chat draws the rooms as cards from this result. See docs/34-ai-assistant/03-places-and-routes.md#quote-links.
 */
class CheckRoomsAndPrices implements Tool
{
    public function __construct(
        private readonly Property $property,
        private readonly KioskWalkIn $walkIn,
        private readonly BookingWindow $window,
    ) {}

    public function description(): Stringable|string
    {
        return 'Find the rooms free for a stay and their prices. Use it before you name any room or price. Adults only: if the guest gives only a total ("4 people", "my family"), first ask how many are adults and whether any children are coming; never assume everyone is an adult. When the guest gives a number of adults, take it that no children are coming unless they say so, and do not ask again. If children are coming, hand over. Then offer the guest the rooms it returns, with one short line on why the stay is worth it, using only facts from the property (for example the breakfast hours, a view or an amenity). To book one, use SendQuoteLink with its room_type_id and rate_plan_id.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after:arrival_date'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $arrival = CarbonImmutable::createFromFormat('Y-m-d', $input['arrival_date'])->startOfDay();
        $departure = CarbonImmutable::createFromFormat('Y-m-d', $input['departure_date'])->startOfDay();

        if (! $this->window->contains($this->property, $arrival)) {
            return $this->json(['rooms' => [], 'note' => 'Stays can be quoted from today to '.BookingWindow::MONTHS_AHEAD.' months ahead. Ask for other dates.']);
        }

        $search = new KioskWalkInData($arrival, (int) $arrival->diffInDays($departure), (int) $input['adults']);
        $rooms = array_values(array_map(fn (KioskWalkInOffer $offer): array => [
            'room_type_id' => $offer->roomType->id,
            'rate_plan_id' => $offer->ratePlan->id,
            'room' => $offer->roomType->name,
            'plan' => $offer->ratePlan->name,
            'total' => (string) $offer->total->getAmount(),
            'currency' => $offer->total->getCurrency()->getCurrencyCode(),
        ], array_filter($this->walkIn->quotable($this->property, $search), fn (KioskWalkInOffer $offer): bool => $offer->available)));

        return $this->json([
            'arrival_date' => $arrival->toDateString(),
            'departure_date' => $departure->toDateString(),
            'nights' => $search->nights,
            'adults' => $search->adults,
            'rooms' => $rooms,
            'note' => $rooms === [] ? 'No room is free for these dates. Suggest other dates, or hand over.' : 'Totals are for the whole stay.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'arrival_date' => $schema->string()->description('Arrival date, YYYY-MM-DD.')->required(),
            'departure_date' => $schema->string()->description('Departure date, YYYY-MM-DD, after the arrival.')->required(),
            'adults' => $schema->integer()->description('Number of adults.')->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
