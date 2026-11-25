<?php

use App\Actions\AiAssistant\RecordAiTurn;
use App\Ai\Agents\PropertyAssistant;
use App\Ai\Tools\CheckRoomsAndPrices;
use App\Ai\Tools\HandOver;
use App\Ai\Tools\PropertyFacts;
use App\Ai\Tools\SendQuoteLink;
use App\Enums\AiOutcome;
use App\Enums\AiPlace;
use App\Enums\AiScope;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Models\Amenity;
use App\Models\ConciergeQuote;
use App\Models\PropertyAiScope;
use App\Models\RoomType;
use App\Services\AiAssistant\AssistantInstructions;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request as ToolRequest;

require_once __DIR__.'/../Support/ConciergeQuoteSetup.php';

/**
 * @return array<string, mixed>
 */
function quoteToolInput(array $with = []): array
{
    return ['arrival_date' => '2026-11-02', 'departure_date' => '2026-11-04', 'adults' => 2, ...$with];
}

test('the lobby agent gets the quote tools only while RoomQuotes is on', function () {
    $setup = conciergeQuoteSetup();
    $thread = AiThread::factory()->for($setup['property'])->create(['place' => AiPlace::ConciergeLobby]);
    $tools = fn (): array => collect(PropertyAssistant::make(thread: $thread)->tools())->map(fn (object $tool): string => $tool::class)->all();

    expect($tools())->toBe([PropertyFacts::class, CheckRoomsAndPrices::class, SendQuoteLink::class, HandOver::class]);

    PropertyAiScope::query()->where('property_id', $setup['property']->id)->where('scope', AiScope::RoomQuotes)->update(['enabled' => false]);
    expect($tools())->toBe([PropertyFacts::class, HandOver::class]);
});

test('the knowledge describes the room types sold online, and only those', function () {
    ['property' => $property, 'type' => $type] = conciergeQuoteSetup();
    $type->forceFill(['description' => 'Queen bed and a balcony over the bay.', 'smoking_allowed' => false])->save();
    $type->amenities()->attach(Amenity::factory()->create(['name' => 'Air conditioning'])->id);
    RoomType::factory()->for($property)->create(['name' => 'Staff Quarters', 'description' => 'Not for guests.']);

    $rooms = app(AssistantInstructions::class)->knowledge($property)['rooms'];

    expect($rooms)->toHaveCount(1)
        ->and($rooms[0])->toMatchArray(['name' => $type->name, 'description' => 'Queen bed and a balcony over the bay.', 'max_adults' => 4, 'max_children' => 2, 'smoking_allowed' => false, 'amenities' => ['Air conditioning']]);
});

test('CheckRoomsAndPrices lists each plan sold online with its stay total', function () {
    $setup = conciergeQuoteSetup();
    $tool = app(CheckRoomsAndPrices::class, ['property' => $setup['property']]);

    $result = json_decode((string) $tool->handle(new ToolRequest(quoteToolInput())), true);

    expect($result['nights'])->toBe(2)
        ->and($result['rooms'])->toBe([
            ['room_type_id' => $setup['type']->id, 'rate_plan_id' => $setup['roomOnly']->id, 'room' => 'Deluxe Double', 'plan' => 'Room only', 'total' => '200.00', 'currency' => 'MYR'],
            ['room_type_id' => $setup['type']->id, 'rate_plan_id' => $setup['breakfast']->id, 'room' => 'Deluxe Double', 'plan' => 'With breakfast', 'total' => '260.00', 'currency' => 'MYR'],
        ]);
});

test('CheckRoomsAndPrices offers nothing outside the booking window and refuses a bad date', function () {
    $setup = conciergeQuoteSetup();
    $tool = app(CheckRoomsAndPrices::class, ['property' => $setup['property']]);

    $result = json_decode((string) $tool->handle(new ToolRequest(quoteToolInput(['arrival_date' => '2027-05-01', 'departure_date' => '2027-05-03']))), true);

    expect($result['rooms'])->toBe([])
        ->and($result['note'])->toContain('6 months ahead');
    expect(fn () => $tool->handle(new ToolRequest(quoteToolInput(['departure_date' => '2026-11-01']))))->toThrow(ValidationException::class);
});

test('SendQuoteLink saves a quote and returns its link, or says why it could not', function () {
    $setup = conciergeQuoteSetup();
    $tool = app(SendQuoteLink::class, ['property' => $setup['property']]);

    $sent = json_decode((string) $tool->handle(new ToolRequest(quoteToolInput(['room_type_id' => $setup['type']->id, 'rate_plan_id' => $setup['breakfast']->id]))), true);
    $quote = ConciergeQuote::query()->sole();

    expect($sent)->sent->toBeTrue()
        ->quote_id->toBe($quote->id)
        ->url->toBe(url("/stay/{$setup['property']->code}/quote/{$quote->token}"))
        ->total->toBe('260.00');

    $refused = json_decode((string) $tool->handle(new ToolRequest(quoteToolInput(['room_type_id' => $setup['type']->id, 'rate_plan_id' => $setup['staff']->id]))), true);

    expect($refused['sent'])->toBeFalse()
        ->and(ConciergeQuote::query()->count())->toBe(1);
});

test('a turn that sent a quote is logged as QuoteSent and linked to the quote', function () {
    $setup = conciergeQuoteSetup();
    $thread = AiThread::factory()->for($setup['property'])->create();
    $quote = savedConciergeQuote($setup, $setup['breakfast']);
    $result = new ToolResult('call_1', 'SendQuoteLink', [], json_encode(['sent' => true, 'quote_id' => $quote->id]));

    $turn = app(RecordAiTurn::class)($thread, 'test-model', new TextUsage, 100, [$result]);

    expect($turn->outcome)->toBe(AiOutcome::QuoteSent)
        ->and($quote->fresh()->ai_turn_id)->toBe($turn->id);
});

test('booking a quote marks the turn that sent it as Booked', function () {
    $setup = conciergeQuoteSetup();
    $thread = AiThread::factory()->for($setup['property'])->create();
    $turn = AiTurn::factory()->create(['ai_thread_id' => $thread->id, 'outcome' => AiOutcome::QuoteSent]);
    $quote = savedConciergeQuote($setup, $setup['breakfast'], ['ai_turn_id' => $turn->id]);

    bookQuote($setup['property'], $quote)->assertSessionHasNoErrors();

    expect($turn->fresh())->outcome->toBe(AiOutcome::Booked)
        ->reservation_id->toBe($quote->fresh()->reservation_id);
});

test('the instructions give today at the property', function () {
    $setup = conciergeQuoteSetup();
    $thread = AiThread::factory()->for($setup['property'])->create();

    expect(app(AssistantInstructions::class)->for($thread))->toContain('Today at the property is Wednesday 14 October 2026 (2026-10-14).');
});
