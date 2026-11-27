<?php

namespace App\Ai\Tools;

use App\Data\Kiosk\KioskWalkInData;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Models\Property;
use App\Services\BookingEngine\BookingWindow;
use App\Services\Concierge\ConciergeQuotes;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Kiosk\KioskWalkInOffer;
use App\Services\Property\GuestAges;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The rooms free for a stay and their prices, one per room type and plan sold online, priced as the booking will charge,
 * children by age. When the chat has an open quote link, its room and plan is marked `current_choice` and comes first,
 * or `current_choice_unavailable` names it when it is not free. The chat draws the rooms as cards from this result.
 * See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class CheckRoomsAndPrices implements Tool
{
    public function __construct(
        private readonly Property $property,
        private readonly KioskWalkIn $walkIn,
        private readonly BookingWindow $window,
        private readonly GuestAges $ages,
        private readonly ConciergeQuotes $quotes,
        private readonly ?AiThread $thread = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Find the rooms free for a stay and their prices. Use it before you name any room or price, and again when the guest changes the dates or the party. '
            .'When the guest gives an arrival and a number of nights, work out the departure date yourself (12 November and 2 nights is 12 to 14 November); never ask for a check-out date the guest already gave as nights. '
            .'It needs the number of adults: if the guest gave none, or only a total ("4 people", "my family"), ask how many adults and whether any children are coming. When the guest gives a number of adults, take it that no children are coming unless they say so. '
            .'Any baby, infant, toddler, kid, child, son or daughter (anak, bayi, budak, 小孩, 宝宝) means children are coming: ask the age of each in one short question, then pass child_ages. '
            .'Then offer the guest the rooms it returns. If no room fits the party, say so and offer other dates or the front desk. '
            .'When a room has current_choice, it is the room and plan of the link already sent: show it first as "Your current choice" with its price and ask the guest to reply yes to keep it, then list the others under "Or pick another". When current_choice_unavailable is set, first say that room and plan is not free, then list the others. '
            .'To book one, use SendQuoteLink with its room_type_id and rate_plan_id and the same adults and child_ages.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after:arrival_date'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'child_ages' => ['sometimes', 'array', 'max:10'],
            'child_ages.*' => ['integer', 'min:0', 'max:17'],
        ]);
        $party = $this->ages->split($this->property, (int) $input['adults'], array_values(array_map('intval', $input['child_ages'] ?? [])));
        $arrival = CarbonImmutable::createFromFormat('Y-m-d', $input['arrival_date'])->startOfDay();
        $departure = CarbonImmutable::createFromFormat('Y-m-d', $input['departure_date'])->startOfDay();

        if (! $this->window->contains($this->property, $arrival)) {
            return $this->json(['rooms' => [], 'note' => 'Stays can be quoted from today to '.BookingWindow::MONTHS_AHEAD.' months ahead. Ask for other dates.']);
        }

        $search = new KioskWalkInData($arrival, (int) $arrival->diffInDays($departure), $party['adults'], $party['childAges']);
        $current = $this->thread === null ? null : $this->quotes->openInChat(
            $this->property->id,
            array_values(AiTurn::query()->where('ai_thread_id', $this->thread->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
        );
        $rooms = array_values(array_map(fn (KioskWalkInOffer $offer): array => [
            'room_type_id' => $offer->roomType->id,
            'rate_plan_id' => $offer->ratePlan->id,
            'room' => $offer->roomType->name,
            'plan' => $offer->ratePlan->name,
            'total' => (string) $offer->total->getAmount(),
            'currency' => $offer->total->getCurrency()->getCurrencyCode(),
            'current_choice' => $current !== null && $current->room_type_id === $offer->roomType->id && $current->rate_plan_id === $offer->ratePlan->id,
        ], array_filter($this->walkIn->quotable($this->property, $search), fn (KioskWalkInOffer $offer): bool => $offer->available)));
        usort($rooms, fn (array $a, array $b): int => $b['current_choice'] <=> $a['current_choice']);
        $currentShown = collect($rooms)->contains('current_choice', true);

        return $this->json([
            'arrival_date' => $arrival->toDateString(),
            'departure_date' => $departure->toDateString(),
            'nights' => $search->nights,
            'adults' => $search->adults,
            'child_ages' => $search->childAges,
            'rooms' => $rooms,
            'current_choice_unavailable' => $current !== null && ! $currentShown ? $current->roomType->name.', '.$current->ratePlan->name : null,
            'note' => $rooms === [] ? 'No room is free for these dates. Suggest other dates, or hand over.' : 'Totals are for the whole stay.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'arrival_date' => $schema->string()->description('Arrival date, YYYY-MM-DD.')->required(),
            'departure_date' => $schema->string()->description('Departure date, YYYY-MM-DD, after the arrival.')->required(),
            'adults' => $schema->integer()->description('Number of adults.')->required(),
            'child_ages' => $schema->array()->items($schema->integer())->description('The age of each child, 0 to 17. Leave out when no children are coming.'),
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
