<?php

use App\Actions\AiAssistant\CloseQuietAiThreads;
use App\Ai\Agents\GuestReplyCheck;
use App\Ai\Agents\PropertyAssistant;
use App\Enums\AiChannel;
use App\Enums\AiKeyStatus;
use App\Enums\AiOutcome;
use App\Enums\AiPlace;
use App\Enums\AiScope;
use App\Enums\AiThreadStatus;
use App\Features\AiAssistant;
use App\Models\AiConnection;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Models\User;
use App\Services\AiAssistant\AiHandoverMessage;
use App\Services\AiAssistant\AiScopes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/AiKeySetup.php';

const CHAT_API_TOKEN = 'est_live_chatapitesttoken';

/**
 * A property with the AI assistant on, the Chat API allowed by the admin (the hotel's switch left off, as it never
 * sees it), a working OpenAI key and one connection with a known token. The reply check passes every reply.
 *
 * @return array{property: Property, connection: AiConnection}
 */
function chatApiSetup(): array
{
    $property = Property::factory()->create();
    Feature::for($property)->activate(AiAssistant::class);
    PropertyAiScope::factory()->for($property)->create(['scope' => AiScope::Api, 'allowed' => true, 'enabled' => false]);
    giveAiKey($property);
    $connection = AiConnection::factory()->for($property)->withToken('est_live_chatapitesttoken')->create();
    GuestReplyCheck::fake(fn (): array => ['guest_only' => true, 'reply' => '']);

    return ['property' => $property, 'connection' => $connection];
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function chatApiMessage(array $body = [], array $headers = [], string $token = CHAT_API_TOKEN): TestResponse
{
    return test()->withToken($token)->withHeaders($headers)->postJson('/api/ai/v1/messages', [
        'channel' => 'whatsapp',
        'thread' => '60123456789@c.us',
        'message' => 'When is breakfast?',
        ...$body,
    ]);
}

test('a message starts a chat API thread, answers in JSON and logs the turn', function () {
    ['property' => $property, 'connection' => $connection] = chatApiSetup();
    PropertyAssistant::fake(['Breakfast is from 7 to 10 am.']);

    $response = chatApiMessage(['guest_name' => 'Aisha'])->assertOk()
        ->assertJsonPath('reply', 'Breakfast is from 7 to 10 am.')
        ->assertJsonPath('escalated', false);

    $thread = AiThread::query()->sole();
    expect($response->json('thread_id'))->toBe($thread->id)
        ->and($thread->property_id)->toBe($property->id)
        ->and($thread->place)->toBe(AiPlace::Api)
        ->and($thread->channel)->toBe(AiChannel::WhatsApp)
        ->and($thread->guest_name)->toBe('Aisha')
        ->and($thread->ai_connection_id)->toBe($connection->id)
        ->and($thread->external_ref_hash)->not->toContain('60123456789')
        ->and(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Answered)
        ->and($connection->refresh()->last_used_at)->not->toBeNull();
    PropertyAssistant::assertPrompted('When is breakfast?');
});

test('the same chat id continues the thread within 24 hours, even through a new connection', function () {
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake(['One.', 'Two.']);
    $first = chatApiMessage()->assertOk()->json('thread_id');

    $other = AiConnection::factory()->for($property)->withToken('est_live_second')->create();
    $this->travel(23)->hours();

    expect(chatApiMessage(token: 'est_live_second')->assertOk()->json('thread_id'))->toBe($first)
        ->and(AiThread::query()->sole()->ai_connection_id)->toBe($other->id);
});

test('a message 24 hours after the last one closes the Open thread and starts a new one', function () {
    chatApiSetup();
    PropertyAssistant::fake(['One.', 'Two.']);
    $first = chatApiMessage()->assertOk()->json('thread_id');

    $this->travel(24)->hours();
    $second = chatApiMessage()->assertOk()->json('thread_id');

    expect($second)->not->toBe($first)
        ->and(AiThread::query()->findOrFail($first)->status)->toBe(AiThreadStatus::Closed);
});

test('a handed-over thread continues within 24 hours, and after that stays for staff while a new one starts', function () {
    chatApiSetup();
    PropertyAssistant::fake(['One.', 'Two.', 'Three.']);
    $first = chatApiMessage()->assertOk()->json('thread_id');
    AiThread::query()->whereKey($first)->update(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()]);

    $this->travel(2)->hours();
    expect(chatApiMessage()->assertOk()->json('thread_id'))->toBe($first);

    $this->travel(25)->hours();
    $second = chatApiMessage()->assertOk()->json('thread_id');

    expect($second)->not->toBe($first)
        ->and(AiThread::query()->findOrFail($first)->status)->toBe(AiThreadStatus::Escalated);
});

test('after staff close a handed-over thread, the same chat id starts a new thread with no history', function () {
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake(['One.', 'Two.']);
    $first = chatApiMessage()->assertOk()->json('thread_id');
    AiThread::query()->whereKey($first)->update(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()]);
    $staff = User::factory()->for($property)->withPermissions(['front-desk.view', 'ai-chats.close'])->create();

    $this->actingAs($staff)->post(route('ai-chats.close', [$property, $first]))->assertRedirect();
    $second = chatApiMessage()->assertOk()->json('thread_id');

    $old = AiThread::query()->findOrFail($first);
    $new = AiThread::query()->findOrFail($second);
    expect($second)->not->toBe($first)
        ->and($old->status)->toBe(AiThreadStatus::Closed)
        ->and($new->status)->toBe(AiThreadStatus::Open)
        ->and($new->agent_conversation_id)->not->toBe($old->agent_conversation_id);
});

test('another channel with the same chat id is another thread', function () {
    chatApiSetup();
    PropertyAssistant::fake(['One.', 'Two.']);

    chatApiMessage()->assertOk();
    chatApiMessage(['channel' => 'telegram'])->assertOk();

    expect(AiThread::query()->count())->toBe(2);
});

test('the chat API answers 404 while the AI assistant, the Chat API switch or the key is missing', function (string $missing) {
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake();

    match ($missing) {
        'feature' => Feature::for($property)->deactivate(AiAssistant::class),
        'switch' => $property->aiScopes()->update(['allowed' => false]),
        'key' => $property->settings->forceFill(['ai_openai_key' => null])->save(),
    };

    chatApiMessage()->assertNotFound()->assertJsonPath('error.code', 'ai_unavailable');
    PropertyAssistant::assertNeverPrompted();
    expect(AiThread::query()->exists())->toBeFalse();
})->with(['feature', 'switch', 'key']);

test('a revoked, wrong or other-kind token gets 401', function () {
    ['connection' => $connection] = chatApiSetup();
    PropertyAssistant::fake();

    chatApiMessage(token: 'wrong')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');

    $connection->tokens()->update(['abilities' => ['pos:*']]);
    chatApiMessage()->assertUnauthorized();

    $connection->tokens()->update(['abilities' => [AiConnection::TOKEN_ABILITY]]);
    $connection->forceFill(['revoked_at' => now()])->save();
    chatApiMessage()->assertUnauthorized();
    PropertyAssistant::assertNeverPrompted();
});

test('missing or too long fields answer 422', function () {
    chatApiSetup();
    PropertyAssistant::fake();

    chatApiMessage(['channel' => 'sms', 'message' => str_repeat('a', 2001), 'thread' => ''])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_fields')
        ->assertJsonStructure(['error' => ['fields' => ['channel', 'message', 'thread']]]);
});

test('a retry with the same idempotency key gets the same reply and makes one turn', function () {
    chatApiSetup();
    PropertyAssistant::fake(['Breakfast is from 7 to 10 am.', 'A second answer.']);

    $first = chatApiMessage(headers: ['Idempotency-Key' => 'exec-1'])->assertOk()->json();
    $again = chatApiMessage(headers: ['Idempotency-Key' => 'exec-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true')->json();

    expect($again)->toBe($first)->and(AiTurn::query()->count())->toBe(1);
});

test('the chat API gets no stay tools, and its quote links go in the reply', function () {
    ['property' => $property] = chatApiSetup();
    foreach ([AiScope::RoomQuotes, AiScope::StayDetails, AiScope::GuestRequests] as $scope) {
        PropertyAiScope::factory()->for($property)->create(['scope' => $scope]);
    }
    $thread = AiThread::factory()->for($property)->create(['place' => AiPlace::Api]);

    $agent = PropertyAssistant::make(thread: $thread);
    $tools = collect($agent->tools())->map(fn (object $tool): string => class_basename($tool))->all();

    expect($tools)->toBe(['PropertyFacts', 'CheckRoomsAndPrices', 'SendQuoteLink', 'HandOver'])
        ->and((string) collect($agent->tools())->first(fn (object $tool): bool => class_basename($tool) === 'SendQuoteLink')->description())
        ->toContain('write the url from the result in your reply');
});

test('at the cap or with a Failing key the guest gets the handover message with no model call', function () {
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake();
    giveAiKey($property, AiKeyStatus::Failing);

    chatApiMessage()->assertOk()->assertJsonPath('escalated', false)->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, 'front desk'));

    PropertyAssistant::assertNeverPrompted();
    expect(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Failed);
});

test('a key OpenAI refuses becomes Failing and the guest gets the handover message', function () {
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake([fn () => throw InsufficientCreditsException::forProvider('openai')]);

    chatApiMessage()->assertOk()->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, 'front desk'));

    expect($property->settings()->first()->ai_key_status)->toBe(AiKeyStatus::Failing)
        ->and(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Failed);
});

test('an OpenAI outage answers 503, logs a Failed turn and is reported', function () {
    Exceptions::fake();
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake([fn () => throw ProviderOverloadedException::forProvider('openai')]);

    chatApiMessage()->assertStatus(503)->assertJsonPath('error.code', 'ai_failed');

    Exceptions::assertReported(ProviderOverloadedException::class);
    expect(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Failed)
        ->and($property->settings()->first()->ai_key_status)->toBe(AiKeyStatus::Working);
});

test('the daily job closes Open chat API threads after 24 quiet hours and Concierge threads after 7 days', function () {
    $property = Property::factory()->create();
    $api = AiThread::factory()->for($property)->create(['place' => AiPlace::Api, 'last_message_at' => now()->subHours(25)]);
    $recentApi = AiThread::factory()->for($property)->create(['place' => AiPlace::Api, 'last_message_at' => now()->subHours(23)]);
    $handedOver = AiThread::factory()->for($property)->create(['place' => AiPlace::Api, 'status' => AiThreadStatus::Escalated, 'last_message_at' => now()->subDays(3)]);
    $lobby = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeLobby, 'last_message_at' => now()->subDays(3)]);

    expect(app(CloseQuietAiThreads::class)())->toBe(1)
        ->and($api->refresh()->status)->toBe(AiThreadStatus::Closed)
        ->and($recentApi->refresh()->status)->toBe(AiThreadStatus::Open)
        ->and($handedOver->refresh()->status)->toBe(AiThreadStatus::Escalated)
        ->and($lobby->refresh()->status)->toBe(AiThreadStatus::Open);
});

test('the Chat API scope is admin-only: allowed means on, and the hotel never sees it', function () {
    ['property' => $property] = chatApiSetup();
    $scopes = app(AiScopes::class);

    expect($scopes->isOn($property, AiScope::Api))->toBeTrue()
        ->and($scopes->forHotel($property))->not->toContain(AiScope::Api);
});

test('the admin Chat API tab shows only while the switch is on', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    ['property' => $property, 'connection' => $connection] = chatApiSetup();

    $this->actingAs($admin)->get(route('admin.properties.chat-api', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'chat-api')
            ->where('chatApiOn', true)
            ->where('chatApi.connections.0.id', $connection->id)
            ->where('chatApi.endpoint', route('api.ai.v1.messages.store'))
            ->missing('chatApi.connections.0.token'));

    $property->aiScopes()->update(['allowed' => false]);

    $this->actingAs($admin)->get(route('admin.properties.chat-api', $property->code))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.properties.features', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('chatApiOn', false)->where('aiAssistant.chatApiConnections', null));
});

test('admins add a connection and see its token once, then revoke it', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    ['property' => $property] = chatApiSetup();
    $property->aiConnections()->delete();

    $this->actingAs($admin)->post(route('admin.properties.chat-api.connections.store', $property->code), ['name' => 'n8n – WhatsApp'])
        ->assertRedirect(route('admin.properties.chat-api', $property->code))
        ->assertInertiaFlash('aiConnectionToken.name', 'n8n – WhatsApp');

    $connection = $property->aiConnections()->sole();
    expect($connection->tokens()->sole()->abilities)->toBe([AiConnection::TOKEN_ABILITY])
        ->and(Activity::query()->where('event', 'api-access.token-created')->exists())->toBeTrue();

    $revoke = route('admin.properties.chat-api.connections.destroy', ['property' => $property->code, 'aiConnection' => $connection->id]);
    $this->actingAs($admin)->delete($revoke)->assertInertiaFlash('toast.type', 'success');
    $this->actingAs($admin)->delete($revoke)->assertInertiaFlash('toast.type', 'error');

    expect($connection->refresh()->revoked_at)->not->toBeNull()->and($connection->tokens()->exists())->toBeFalse();
});

test('admins cannot add a connection while the switch is off, nor revoke another property\'s', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    ['property' => $property] = chatApiSetup();
    $other = AiConnection::factory()->create();
    $property->aiScopes()->update(['allowed' => false]);

    $this->actingAs($admin)->post(route('admin.properties.chat-api.connections.store', $property->code), ['name' => 'n8n'])->assertNotFound();
    $this->actingAs($admin)->delete(route('admin.properties.chat-api.connections.destroy', ['property' => $property->code, 'aiConnection' => $other->id]))->assertNotFound();

    expect($other->refresh()->revoked_at)->toBeNull();
});

test('the hotel sees a read-only chat API line, and the inbox shows the channel and guest name', function () {
    ['property' => $property] = chatApiSetup();
    $owner = User::factory()->for($property)->owner()->create();
    PropertyAssistant::fake(['Hi.']);
    chatApiMessage(['guest_name' => 'Aisha'])->assertOk();

    $this->actingAs($owner)->get(route('settings.ai-assistant.places', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('status.chatApiConnections', 1));

    $this->actingAs($owner)->get(route('ai-chats.all', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threads.data.0.placeLabel', 'Chat API · WhatsApp')
            ->where('threads.data.0.guestName', 'Aisha'));
});

test('a reply with the model\'s notes in it is sent cleaned, and the chat keeps the cleaned text', function () {
    chatApiSetup();
    PropertyAssistant::fake(['Need year? Use next. So "For 5 to 8 December 2026, how many adults?" Good. Ask.']);
    GuestReplyCheck::fake([
        ['guest_only' => false, 'reply' => 'For 5 to 8 December 2026, how many adults?'],
        ['guest_only' => true, 'reply' => ''],
    ]);

    chatApiMessage(['message' => '5-8 dec'])->assertOk()
        ->assertJsonPath('reply', 'For 5 to 8 December 2026, how many adults?')
        ->assertJsonPath('escalated', false);

    $thread = AiThread::query()->sole();
    $stored = DB::table('agent_conversation_messages')->where('conversation_id', $thread->agent_conversation_id)->where('role', 'assistant')->sole();
    expect($stored->content)->toBe('For 5 to 8 December 2026, how many adults?')
        ->and(collect(json_decode($stored->steps, true))->pluck('content')->implode(''))->toBe('For 5 to 8 December 2026, how many adults?')
        ->and($thread->status)->toBe(AiThreadStatus::Open)
        ->and(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Answered);
    GuestReplyCheck::assertPrompted('Need year? Use next. So "For 5 to 8 December 2026, how many adults?" Good. Ask.');
});

test('a reply that cannot be cleaned is held back, and the guest gets the handover message', function (array $checks) {
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake(['Need link. So "Here is your link: https://example.test/q/abc" Good.']);
    GuestReplyCheck::fake($checks);

    chatApiMessage()->assertOk()
        ->assertJsonPath('reply', app(AiHandoverMessage::class)->forProperty($property))
        ->assertJsonPath('escalated', true);

    $thread = AiThread::query()->sole();
    expect($thread->status)->toBe(AiThreadStatus::Escalated)
        ->and($thread->handover_summary)->toContain('could not write a clear reply')
        ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->sole()->content)->toBe(app(AiHandoverMessage::class)->forProperty($property))
        ->and(AiTurn::query()->sole()->outcome)->toBe(AiOutcome::Escalated);
})->with([
    'the cleaned reply still has notes' => [[
        ['guest_only' => false, 'reply' => 'Here is your link: https://example.test/q/abc Good.'],
        ['guest_only' => false, 'reply' => ''],
    ]],
    'the cleaned reply lost the link' => [[
        ['guest_only' => false, 'reply' => 'Here is your link.'],
    ]],
]);

test('when the reply check fails to run, the guest gets the handover message', function () {
    ['property' => $property] = chatApiSetup();
    PropertyAssistant::fake(['Breakfast is from 7 to 10 am.']);
    GuestReplyCheck::fake(fn () => throw new ProviderOverloadedException('Overloaded.'));
    Exceptions::fake();

    chatApiMessage()->assertOk()
        ->assertJsonPath('reply', app(AiHandoverMessage::class)->forProperty($property))
        ->assertJsonPath('escalated', true);

    Exceptions::assertReported(ProviderOverloadedException::class);
});
