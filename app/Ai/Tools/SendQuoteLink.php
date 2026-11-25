<?php

namespace App\Ai\Tools;

use App\Actions\Concierge\CreateConciergeQuote;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Make a quote link for a room the guest chose from `CheckRoomsAndPrices`. Nothing is booked: the guest books on the
 * link's page. The chat draws the link as a card from this result, and `RecordAiTurn` links the quote to the turn by its
 * `quote_id`. A chat API thread has no cards, so there the model writes the url in its reply. See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class SendQuoteLink implements Tool
{
    public function __construct(
        private readonly Property $property,
        private readonly CreateConciergeQuote $create,
        private readonly bool $linkInReply = false,
    ) {}

    public function description(): Stringable|string
    {
        $link = $this->linkInReply
            ? 'This chat shows no cards, so write the url from the result in your reply.'
            : 'The guest sees the link as a card, so do not repeat the link in your reply.';

        return 'Make a booking link for one room the guest chose from CheckRoomsAndPrices, with the same dates and adults. Nothing is booked until the guest books on the link, which lasts 24 hours. '.$link;
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'rate_plan_id' => ['required', 'integer'],
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after:arrival_date'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $result = ($this->create)(
            $this->property,
            (int) $input['room_type_id'],
            (int) $input['rate_plan_id'],
            CarbonImmutable::createFromFormat('Y-m-d', $input['arrival_date']),
            CarbonImmutable::createFromFormat('Y-m-d', $input['departure_date']),
            (int) $input['adults'],
        );

        if ($result->quote === null) {
            return (string) json_encode(['sent' => false, 'note' => match ($result->refusal) {
                'outside_window' => 'Those dates cannot be quoted. Ask for other dates.',
                'no_room_available' => 'That room is no longer free for those dates. Check rooms again.',
                default => 'The dates are not valid. Check them with the guest.',
            }]);
        }

        $quote = $result->quote->load(['roomType', 'ratePlan']);

        return (string) json_encode([
            'sent' => true,
            'quote_id' => $quote->id,
            'url' => route('concierge.quote', ['property' => $this->property->code, 'quote' => $quote->token]),
            'room' => $quote->roomType->name,
            'plan' => $quote->ratePlan->name,
            'arrival_date' => $quote->arrival_date->toDateString(),
            'departure_date' => $quote->departure_date->toDateString(),
            'adults' => $quote->adults,
            'total' => (string) $quote->quoted_total,
            'currency' => $this->property->settings->currency,
            'expires_at' => $quote->expires_at->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_type_id' => $schema->integer()->description('room_type_id from CheckRoomsAndPrices.')->required(),
            'rate_plan_id' => $schema->integer()->description('rate_plan_id from CheckRoomsAndPrices.')->required(),
            'arrival_date' => $schema->string()->description('Arrival date, YYYY-MM-DD.')->required(),
            'departure_date' => $schema->string()->description('Departure date, YYYY-MM-DD.')->required(),
            'adults' => $schema->integer()->description('Number of adults.')->required(),
        ];
    }
}
