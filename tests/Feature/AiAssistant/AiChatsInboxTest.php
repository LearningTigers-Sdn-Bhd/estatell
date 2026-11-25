<?php

use App\Actions\AiAssistant\EscalateAiThread;
use App\Actions\AiAssistant\ResolveAiThread;
use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Features\AiAssistant;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Notifications\AiThreadEscalated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ConciergeSetup.php';

beforeEach(function () {
    Notification::fake();
});

/**
 * A Concierge property with the AI assistant on and a front desk user.
 *
 * @return array{property: Property, unit: ReservationUnit, guest: Guest, staff: User}
 */
function aiInboxSetup(): array
{
    $setup = conciergeSetup();
    Feature::for($setup['property'])->activate(AiAssistant::class);

    return [...$setup, 'staff' => User::factory()->for($setup['property'])->withPermissions(['front-desk.view'])->create()];
}

/**
 * Save one chat message in the thread's SDK conversation.
 */
function aiInboxMessage(AiThread $thread, string $role, string $text, ?CarbonInterface $at = null): void
{
    ConversationMessage::query()->forceCreate([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $thread->agent_conversation_id,
        'agent' => 'App\\Ai\\Agents\\PropertyAssistant',
        'role' => $role,
        'content' => $text,
        'attachments' => [],
        'steps' => [],
        'usage' => [],
        'meta' => [],
        'status' => 'completed',
        'created_at' => $at ?? now(),
        'updated_at' => $at ?? now(),
    ]);
}

test('the Handed over tab lists escalated chats, oldest handover first, with the summary', function () {
    ['property' => $property, 'unit' => $unit, 'guest' => $guest, 'staff' => $staff] = aiInboxSetup();
    $newer = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeStay, 'reservation_unit_id' => $unit->id, 'status' => AiThreadStatus::Escalated, 'escalated_at' => now()->subMinutes(10), 'handover_summary' => 'Wants a late check-out.']);
    $older = AiThread::factory()->for($property)->create(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()->subHour()]);
    aiInboxMessage($older, 'user', 'Can a tour bus park here?');
    AiThread::factory()->for($property)->create();
    AiTurn::factory()->for($newer, 'thread')->create(['property_id' => $property->id]);

    $this->actingAs($staff)->get(route('ai-chats.index', $property))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ai-chats/index')
            ->where('tab', 'handed-over')
            ->where('handedOverCount', 2)
            ->has('threads.data', 2)
            ->where('threads.data.0.id', $older->id)
            ->where('threads.data.0.guestName', null)
            ->where('threads.data.0.preview', 'Can a tour bus park here?')
            ->where('threads.data.0.hasSummary', false)
            ->where('threads.data.1.id', $newer->id)
            ->where('threads.data.1.guestName', $guest->full_name)
            ->where('threads.data.1.preview', 'Wants a late check-out.')
            ->where('threads.data.1.turns', 1)
            ->where('selectedThread', null));
});

test('the All tab lists every chat, last message first, and filters by place', function () {
    ['property' => $property, 'unit' => $unit, 'staff' => $staff] = aiInboxSetup();
    $lobby = AiThread::factory()->for($property)->create(['last_message_at' => now()->subHour()]);
    $stay = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeStay, 'reservation_unit_id' => $unit->id, 'status' => AiThreadStatus::Closed, 'last_message_at' => now()->subMinute()]);
    AiThread::factory()->create();

    $this->actingAs($staff)->get(route('ai-chats.all', $property))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'all')
            ->has('threads.data', 2)
            ->where('threads.data.0.id', $stay->id)
            ->where('threads.data.1.id', $lobby->id));

    $this->get(route('ai-chats.all', [$property, 'filter' => ['place' => 'concierge-lobby']]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('threads.data', 1)->where('threads.data.0.id', $lobby->id));
});

test('a chat opens with its messages, and another property chat does not', function () {
    ['property' => $property, 'staff' => $staff] = aiInboxSetup();
    $thread = AiThread::factory()->for($property)->create(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()]);
    aiInboxMessage($thread, 'user', 'Is breakfast included?', now()->subMinutes(2));
    aiInboxMessage($thread, 'assistant', 'Yes, from 7 to 10 am.', now()->subMinute());
    $other = AiThread::factory()->create();

    $this->actingAs($staff)->get(route('ai-chats.index', [$property, 'thread' => $thread->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selectedThread.row.id', $thread->id)
            ->where('selectedThread.canResolve', true)
            ->has('selectedThread.messages', 2)
            ->where('selectedThread.messages.0.role', 'user'));

    $this->get(route('ai-chats.index', [$property, 'thread' => $other->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selectedThread', null));
    $this->post(route('ai-chats.resolve', [$property, $other->id]))->assertNotFound();
});

test('the inbox never shows a staff member helper chat', function () {
    ['property' => $property, 'staff' => $staff] = aiInboxSetup();
    $helper = AiThread::factory()->for($property)->create(['place' => AiPlace::Staff, 'user_id' => User::factory()->for($property)->create()->id, 'status' => AiThreadStatus::Escalated, 'escalated_at' => now()]);

    $this->actingAs($staff)->get(route('ai-chats.all', [$property, 'thread' => $helper->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('threads.data', 0)
            ->where('handedOverCount', 0)
            ->where('selectedThread', null)
            ->where('places', fn ($places): bool => ! collect($places)->contains('value', 'staff')));
    $this->post(route('ai-chats.resolve', [$property, $helper->id]))->assertNotFound();
});

test('the inbox needs the AI assistant feature and front-desk.view', function () {
    ['property' => $property, 'staff' => $staff] = aiInboxSetup();
    $housekeeper = User::factory()->for($property)->withPermissions(['housekeeping.view'])->create();

    $this->actingAs($housekeeper)->get(route('ai-chats.index', $property))->assertForbidden();

    Feature::for($property)->deactivate(AiAssistant::class);
    $this->actingAs($staff)->get(route('ai-chats.index', $property))->assertNotFound();
});

test('resolve moves a handed-over chat back to Open once and logs it', function () {
    ['property' => $property, 'staff' => $staff] = aiInboxSetup();
    $thread = AiThread::factory()->for($property)->create(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()]);

    $this->actingAs($staff)->post(route('ai-chats.resolve', [$property, $thread->id]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect($thread->fresh())
        ->status->toBe(AiThreadStatus::Open)
        ->resolved_by_user_id->toBe($staff->id)
        ->resolved_at->not->toBeNull()
        ->and(Activity::query()->where('event', 'ai-assistant.thread-resolved')->sole()->properties['ai_thread_id'])->toBe($thread->id);

    $this->post(route('ai-chats.resolve', [$property, $thread->id]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'This chat is no longer handed over.');

    expect(Activity::query()->where('event', 'ai-assistant.thread-resolved')->count())->toBe(1);
});

test('a handover after a resolve saves the new summary, clears the resolve and alerts again', function () {
    ['property' => $property, 'staff' => $staff] = aiInboxSetup();
    $thread = AiThread::factory()->for($property)->create();
    $escalate = app(EscalateAiThread::class);

    $escalate($thread, 'First question.');
    app(ResolveAiThread::class)($staff, $thread->fresh());
    $escalate($thread->fresh(), 'Second question.');

    expect($thread->fresh())
        ->status->toBe(AiThreadStatus::Escalated)
        ->handover_summary->toBe('Second question.')
        ->resolved_at->toBeNull()
        ->resolved_by_user_id->toBeNull();
    Notification::assertSentTimes(AiThreadEscalated::class, 2);
});

test('the inbox runs the same number of queries for more chats', function () {
    ['property' => $property, 'unit' => $unit, 'staff' => $staff] = aiInboxSetup();
    $makeThread = function () use ($property, $unit): void {
        $thread = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeStay, 'reservation_unit_id' => $unit->id, 'status' => AiThreadStatus::Escalated, 'escalated_at' => now(), 'handover_summary' => 'Help.']);
        aiInboxMessage($thread, 'user', 'Hello');
    };
    $makeThread();
    Model::preventLazyLoading();

    try {
        $this->actingAs($staff)->get(route('ai-chats.index', $property))->assertOk();
        DB::enableQueryLog();
        $this->get(route('ai-chats.index', $property))->assertOk();
        $few = count(DB::getQueryLog());

        $makeThread();
        $makeThread();
        DB::flushQueryLog();
        $this->get(route('ai-chats.index', $property))->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    } finally {
        Model::preventLazyLoading(false);
    }
});
