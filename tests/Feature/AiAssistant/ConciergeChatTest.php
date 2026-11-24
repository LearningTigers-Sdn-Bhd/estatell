<?php

use App\Ai\Agents\PropertyAssistant;
use App\Ai\Tools\HandOver;
use App\Ai\Tools\PropertyFacts;
use App\Enums\AiKeyStatus;
use App\Enums\AiOutcome;
use App\Enums\AiPlace;
use App\Enums\AiScope;
use App\Enums\AiThreadStatus;
use App\Features\AiAssistant;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Models\PropertyFaq;
use App\Models\PropertyWifiNetwork;
use App\Models\ReservationUnit;
use App\Services\AiAssistant\AiTurnCost;
use App\Services\AiAssistant\AssistantInstructions;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\Error as StreamError;
use Laravel\Ai\Tools\Request as ToolRequest;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/AiKeySetup.php';
require_once __DIR__.'/../Support/ConciergeSetup.php';

/**
 * A Concierge property with the AI assistant on, its Concierge scope allowed and enabled, and a working OpenAI key.
 *
 * @return array{property: Property, unit: ReservationUnit, token: string}
 */
function conciergeChatSetup(): array
{
    $setup = conciergeSetup();
    Feature::for($setup['property'])->activate(AiAssistant::class);
    PropertyAiScope::factory()->for($setup['property'])->create(['scope' => AiScope::Concierge]);
    giveAiKey($setup['property']);

    return $setup;
}

function lobbyChat(Property $property, string $text, ?string $thread = null): TestResponse
{
    return test()->post("/stay/{$property->code}/ai/messages", ['id' => $thread ?? (string) Str::uuid(), 'text' => $text]);
}

/**
 * The reply text of a streamed chat response: its text deltas joined.
 */
function chatReply(TestResponse $response): string
{
    preg_match_all('/^data: (\{.*\})$/m', $response->streamedContent(), $lines);

    return collect($lines[1])->map(fn (string $line): array => json_decode($line, true))->where('type', 'text-delta')->pluck('delta')->implode('');
}

test('the chat routes answer 404 while the AI assistant or its Concierge scope is off', function () {
    ['property' => $property, 'token' => $token] = conciergeSetup();
    PropertyAssistant::fake();

    lobbyChat($property, 'Hi')->assertNotFound();
    $this->post("/c/{$token}/ai/messages", ['text' => 'Hi'])->assertNotFound();

    Feature::for($property)->activate(AiAssistant::class);
    PropertyAiScope::factory()->for($property)->create(['scope' => AiScope::Concierge, 'enabled' => false]);

    lobbyChat($property, 'Hi')->assertNotFound();
    $this->get("/c/{$token}/ai/messages")->assertNotFound();
    PropertyAssistant::assertNeverPrompted();
});

test('the lobby and stay pages show the chat only while its scope is on and the hotel has a key', function () {
    ['property' => $property, 'token' => $token] = conciergeSetup();

    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('aiChat', false));

    Feature::for($property)->activate(AiAssistant::class);
    PropertyAiScope::factory()->for($property)->create(['scope' => AiScope::Concierge]);
    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('aiChat', false));

    giveAiKey($property);

    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('aiChat', true));
    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page->where('aiChat', true));
});

test('a lobby message starts a thread, streams the reply and logs the turn', function () {
    ['property' => $property] = conciergeChatSetup();
    PropertyAssistant::fake(['Breakfast is from 7 to 10 am.']);
    $id = (string) Str::uuid();

    $response = lobbyChat($property, 'When is breakfast?', $id)->assertOk();

    expect(chatReply($response))->toBe('Breakfast is from 7 to 10 am.');
    PropertyAssistant::assertPrompted('When is breakfast?');

    $thread = AiThread::query()->findOrFail($id);
    expect($thread->property_id)->toBe($property->id)
        ->and($thread->place)->toBe(AiPlace::ConciergeLobby)
        ->and($thread->status)->toBe(AiThreadStatus::Open)
        ->and($thread->reservation_unit_id)->toBeNull()
        ->and(ConversationMessage::query()->where('conversation_id', $thread->agent_conversation_id)->count())->toBe(2);

    $turn = AiTurn::query()->sole();
    expect($turn->ai_thread_id)->toBe($id)->and($turn->outcome)->toBe(AiOutcome::Answered)->and($turn->place)->toBe(AiPlace::ConciergeLobby);

    $this->getJson("/stay/{$property->code}/ai/messages?thread={$id}")->assertOk()
        ->assertJsonPath('thread', $id)
        ->assertJsonCount(2, 'messages');
});

test('a reply the provider fails is reported and logged as a Failed turn', function () {
    ['property' => $property] = conciergeChatSetup();
    Exceptions::fake();
    PropertyAssistant::fake([fn () => throw new StreamErrorException(new StreamError('err_1', 'insufficient_quota', 'You exceeded your current quota.', false, 0))]);

    $response = lobbyChat($property, 'When is breakfast?')->assertOk();

    expect($response->streamedContent())->toContain('"type":"error"');
    Exceptions::assertReported(StreamErrorException::class);
    expect(AiTurn::query()->sole())->outcome->toBe(AiOutcome::Failed)->input_tokens->toBe(0)
        ->and($property->settings()->first()->ai_key_status)->toBe(AiKeyStatus::Failing);
});

test('a provider error that is not about the key keeps the key Working', function () {
    ['property' => $property] = conciergeChatSetup();
    Exceptions::fake();
    PropertyAssistant::fake([fn () => throw ProviderOverloadedException::forProvider('openai')]);

    lobbyChat($property, 'When is breakfast?')->assertOk()->streamedContent();

    expect(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Failed)
        ->and($property->settings()->first()->ai_key_status)->toBe(AiKeyStatus::Working);
});

test('a key OpenAI refuses for credit goes Failing', function () {
    ['property' => $property] = conciergeChatSetup();
    PropertyAssistant::fake([fn () => throw InsufficientCreditsException::forProvider('openai')]);

    lobbyChat($property, 'Hi')->assertOk()->streamedContent();

    expect($property->settings()->first()->ai_key_status)->toBe(AiKeyStatus::Failing);
});

test('while the key fails the model is not called and the guest gets the handover message', function () {
    ['property' => $property] = conciergeChatSetup();
    giveAiKey($property, AiKeyStatus::Failing);
    $property->settings->forceFill(['ai_handover_message' => 'Our team will reply soon.'])->save();
    PropertyAssistant::fake();

    $response = lobbyChat($property, 'Hello')->assertOk();

    expect(chatReply($response))->toBe('Our team will reply soon.');
    PropertyAssistant::assertNeverPrompted();
    expect(AiTurn::query()->latest('id')->first())
        ->outcome->toBe(AiOutcome::Failed)
        ->input_tokens->toBe(0);
});

test('a second lobby message continues the same thread', function () {
    ['property' => $property] = conciergeChatSetup();
    PropertyAssistant::fake(['One.', 'Two.']);
    $id = (string) Str::uuid();

    lobbyChat($property, 'First', $id)->assertOk()->streamedContent();
    lobbyChat($property, 'Second', $id)->assertOk()->streamedContent();

    expect(AiThread::query()->count())->toBe(1)
        ->and(ConversationMessage::query()->where('conversation_id', AiThread::query()->sole()->agent_conversation_id)->count())->toBe(4);
});

test('a lobby id of another property is refused and its messages are not shown', function () {
    ['property' => $property] = conciergeChatSetup();
    $other = AiThread::factory()->create();
    PropertyAssistant::fake();

    lobbyChat($property, 'Hi', $other->id)->assertStatus(409);
    $this->getJson("/stay/{$property->code}/ai/messages?thread={$other->id}")->assertOk()->assertJsonPath('thread', null)->assertJsonCount(0, 'messages');
    PropertyAssistant::assertNeverPrompted();
});

test('a stay message uses the stay\'s own thread and ignores the id the browser sends', function () {
    ['property' => $property, 'unit' => $unit, 'token' => $token] = conciergeChatSetup();
    $lobby = AiThread::factory()->for($property)->create();
    PropertyAssistant::fake(['Hello.', 'Again.']);

    $this->post("/c/{$token}/ai/messages", ['id' => $lobby->id, 'text' => 'Hi'])->assertOk()->streamedContent();
    $this->post("/c/{$token}/ai/messages", ['text' => 'Hi again'])->assertOk()->streamedContent();

    $stay = AiThread::query()->where('place', AiPlace::ConciergeStay)->sole();
    expect($stay->reservation_unit_id)->toBe($unit->id)
        ->and($lobby->turns()->count())->toBe(0)
        ->and($stay->turns()->count())->toBe(2);

    $this->getJson("/c/{$token}/ai/messages")->assertOk()->assertJsonPath('thread', $stay->id)->assertJsonCount(4, 'messages');
});

test('a message is text only and limited in length', function () {
    ['property' => $property] = conciergeChatSetup();
    PropertyAssistant::fake();

    lobbyChat($property, '')->assertSessionHasErrors('text');
    lobbyChat($property, str_repeat('a', 2001))->assertSessionHasErrors('text');
    $this->post("/stay/{$property->code}/ai/messages", ['text' => 'Hi'])->assertSessionHasErrors('id');
    PropertyAssistant::assertNeverPrompted();
});

test('at the monthly cap the model is not called and the guest gets the handover message', function () {
    ['property' => $property] = conciergeChatSetup();
    $property->settings->forceFill(['ai_monthly_cap_micros' => 5000, 'ai_handover_message' => 'Our team will reply soon.'])->save();
    AiTurn::factory()->create(['ai_thread_id' => AiThread::factory()->for($property)->create()->id, 'cost_micros' => 5000]);
    PropertyAssistant::fake();

    $response = lobbyChat($property, 'Hi')->assertOk();

    expect(chatReply($response))->toBe('Our team will reply soon.');
    PropertyAssistant::assertNeverPrompted();
    expect(AiTurn::query()->latest('id')->first())
        ->outcome->toBe(AiOutcome::CapReached)
        ->input_tokens->toBe(0)
        ->cost_micros->toBe(0);
});

test('last month\'s cost does not count toward the cap', function () {
    ['property' => $property] = conciergeChatSetup();
    $property->settings->forceFill(['ai_monthly_cap_micros' => 5000])->save();
    AiTurn::factory()->create(['ai_thread_id' => AiThread::factory()->for($property)->create()->id, 'cost_micros' => 9000, 'created_at' => now()->subMonth()]);
    PropertyAssistant::fake(['Hello.']);

    lobbyChat($property, 'Hi')->assertOk()->streamedContent();

    PropertyAssistant::assertPromptedTimes(1);
});

test('the instructions hold what a guest may see, never Wi-Fi or hidden FAQs', function () {
    ['property' => $property] = conciergeChatSetup();
    PropertyFaq::factory()->for($property)->create(['question' => 'Is there a pool?', 'answer' => 'Yes, on level 5.']);
    PropertyFaq::factory()->for($property)->create(['question' => 'Staff parking?', 'answer' => 'Hidden answer', 'active' => false]);
    PropertyWifiNetwork::factory()->for($property)->create(['password' => 'secret-wifi-9']);
    $thread = AiThread::factory()->for($property)->create();

    $instructions = app(AssistantInstructions::class)->for($thread);

    expect($instructions)->toContain('Yes, on level 5.')
        ->toContain($property->name)
        ->not->toContain('Hidden answer')
        ->not->toContain('secret-wifi-9');
});

test('the agent gets only the tools its place and scopes allow', function () {
    ['property' => $property] = conciergeChatSetup();
    $thread = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeStay]);

    $tools = collect(PropertyAssistant::make(thread: $thread)->tools())->map(fn (object $tool): string => $tool::class)->all();

    expect($tools)->toBe([PropertyFacts::class, HandOver::class]);
});

test('the HandOver tool hands its own thread over once', function () {
    ['property' => $property] = conciergeChatSetup();
    $thread = AiThread::factory()->for($property)->create();
    $tool = app(HandOver::class, ['thread' => $thread]);

    expect((string) $tool->handle(new ToolRequest(['reason' => 'Refund'])))->toContain("I'll pass this to our front desk.");
    $firstAt = $thread->fresh()->escalated_at;
    $this->travel(5)->minutes();
    $tool->handle(new ToolRequest(['reason' => 'Again']));

    expect($thread->fresh())->status->toBe(AiThreadStatus::Escalated)
        ->escalated_at->toEqual($firstAt);
});

test('the PropertyFacts tool returns one guide part and refuses an unknown one', function () {
    ['property' => $property] = conciergeChatSetup();
    PropertyFaq::factory()->for($property)->create(['answer' => 'Yes, on level 5.']);
    $tool = app(PropertyFacts::class, ['property' => $property]);

    expect((string) $tool->handle(new ToolRequest(['topic' => 'faqs'])))->toContain('Yes, on level 5.');
    expect(fn () => $tool->handle(new ToolRequest(['topic' => 'wifi'])))->toThrow(ValidationException::class);
});

test('a turn costs its tokens at the model\'s price in micros', function () {
    config(['ai-pricing.models.test-model' => ['input' => '0.10', 'cached_input' => '0.01', 'output' => '0.50']]);

    $usage = new TextUsage(inputTokens: 10_000, outputTokens: 1_000, cacheReadInputTokens: 4_000);

    expect(app(AiTurnCost::class)->micros('test-model', $usage))->toBe(600 + 40 + 500)
        ->and(app(AiTurnCost::class)->micros('unknown-model', $usage))->toBe(0);
});
