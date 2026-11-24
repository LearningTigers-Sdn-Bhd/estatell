<?php

use App\Actions\AiAssistant\EscalateAiThread;
use App\Ai\Tools\HandOver;
use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Enums\WebhookEvent;
use App\Jobs\BroadcastWebhookJob;
use App\Models\AiThread;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\AiThreadEscalated;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request as ToolRequest;

require_once __DIR__.'/../Support/ConciergeSetup.php';

beforeEach(function () {
    Notification::fake();
    Queue::fake([BroadcastWebhookJob::class]);
});

test('a handover tells front desk staff once, with the summary and the guest of the stay', function () {
    $setup = conciergeSetup();
    $frontDesk = User::factory()->for($setup['property'])->withPermissions(['front-desk.view'])->create();
    $other = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();
    $thread = AiThread::factory()->for($setup['property'])->create(['place' => AiPlace::ConciergeStay, 'reservation_unit_id' => $setup['unit']->id]);
    $escalate = app(EscalateAiThread::class);

    expect($escalate($thread, 'The guest wants a late check-out tomorrow.'))->toBeTrue()
        ->and($escalate($thread->fresh(), 'Again.'))->toBeFalse();

    Notification::assertSentTimes(AiThreadEscalated::class, 1);
    Notification::assertSentTo($frontDesk, AiThreadEscalated::class, fn (AiThreadEscalated $message): bool => $message->summary === 'The guest wants a late check-out tomorrow.'
        && $message->guestName === $setup['guest']->full_name
        && $message->reservationId === $setup['unit']->reservation_id
        && $message->placeLabel === 'Concierge stay');
    Notification::assertNotSentTo($other, AiThreadEscalated::class);
    expect($thread->fresh()->status)->toBe(AiThreadStatus::Escalated);
});

test('a lobby handover has no guest and links to the dashboard', function () {
    $setup = conciergeSetup();
    $frontDesk = User::factory()->for($setup['property'])->withPermissions(['front-desk.view'])->create();
    $thread = AiThread::factory()->for($setup['property'])->create();

    app(EscalateAiThread::class)($thread, 'Asks about parking for a bus.');

    Notification::assertSentTo($frontDesk, AiThreadEscalated::class, function (AiThreadEscalated $message) use ($frontDesk, $setup): bool {
        $data = $message->toArray($frontDesk);

        return $message->guestName === null
            && $message->reservationId === null
            && $data['url'] === route('dashboard', ['property' => $setup['property']->code])
            && str_contains($data['body'], 'Asks about parking for a bus.');
    });
});

test('a handover sends ai.escalated with the thread and the summary, not the chat', function () {
    $setup = conciergeSetup();
    WebhookEndpoint::factory()->forProperty($setup['property'])->hearing([WebhookEvent::AiEscalated])->create();
    $thread = AiThread::factory()->for($setup['property'])->create(['place' => AiPlace::ConciergeStay, 'reservation_unit_id' => $setup['unit']->id]);

    app(EscalateAiThread::class)($thread, 'Wants a refund for the minibar.');

    Queue::assertPushed(BroadcastWebhookJob::class, 1);
    Queue::pushed(BroadcastWebhookJob::class)->each(fn (BroadcastWebhookJob $job) => app()->call([$job, 'handle']));

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->payload['event'])->toBe('ai.escalated')
        ->and($delivery->payload['data'])->toEqual([
            'thread' => [
                'id' => $thread->id,
                'place' => 'concierge-stay',
                'status' => 'escalated',
                'reservation_id' => $setup['unit']->reservation_id,
                'escalated_at' => $thread->fresh()->escalated_at->toIso8601String(),
            ],
            'summary' => 'Wants a refund for the minibar.',
        ]);
});

test('a closed thread is not handed over and sends nothing', function () {
    $setup = conciergeSetup();
    WebhookEndpoint::factory()->forProperty($setup['property'])->hearing([WebhookEvent::AiEscalated])->create();
    User::factory()->for($setup['property'])->withPermissions(['front-desk.view'])->create();
    $thread = AiThread::factory()->for($setup['property'])->create(['status' => AiThreadStatus::Closed]);

    expect(app(EscalateAiThread::class)($thread, 'Anything.'))->toBeFalse();

    Notification::assertNothingSent();
    Queue::assertNotPushed(BroadcastWebhookJob::class);
});

test('the HandOver tool passes its summary to the handover', function () {
    $setup = conciergeSetup();
    $frontDesk = User::factory()->for($setup['property'])->withPermissions(['front-desk.view'])->create();
    $thread = AiThread::factory()->for($setup['property'])->create();

    app(HandOver::class, ['thread' => $thread])->handle(new ToolRequest(['reason' => 'Refund', 'summary' => 'Asks for a refund of the deposit.']));

    Notification::assertSentTo($frontDesk, AiThreadEscalated::class, fn (AiThreadEscalated $message): bool => $message->summary === 'Asks for a refund of the deposit.');
});
