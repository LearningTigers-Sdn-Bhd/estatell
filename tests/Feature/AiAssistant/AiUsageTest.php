<?php

use App\Actions\AiAssistant\CloseQuietAiThreads;
use App\Actions\AiAssistant\PurgeAiMessageText;
use App\Actions\AiAssistant\RecordAiTurn;
use App\Enums\AiOutcome;
use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Enums\AiTool;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\PermissionEnum;
use App\Features\AiAssistant;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\UnitNight;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\AiCapNearlyReached;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ConciergeSetup.php';

/**
 * An owner of a property in Kuala Lumpur time with the AI assistant feature on.
 *
 * @return array{owner: User, property: Property}
 */
function aiUsageSetup(): array
{
    $owner = User::factory()->owner()->create();
    Feature::for($owner->property)->activate(AiAssistant::class);
    PropertySetting::factory()->for($owner->property)->create(['time_zone' => 'Asia/Kuala_Lumpur']);
    $owner->property->unsetRelation('settings');

    return ['owner' => $owner, 'property' => $owner->property];
}

function aiUsageTurn(AiThread $thread, array $attributes = []): AiTurn
{
    return AiTurn::factory()->create(['ai_thread_id' => $thread->id, 'place' => $thread->place, ...$attributes]);
}

test('the usage tab needs the feature and settings.manage, and is scoped to the property', function () {
    $plain = User::factory()->owner()->create();
    $this->actingAs($plain)->get(route('settings.ai-assistant.usage', $plain->property->code))->assertNotFound();

    ['property' => $property] = aiUsageSetup();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $this->actingAs($staff)->get(route('settings.ai-assistant.usage', $property->code))->assertForbidden();

    $this->actingAs(aiUsageSetup()['owner'])->get(route('settings.ai-assistant.usage', $property->code))->assertNotFound();
});

test('the usage tab sums the month in the property time zone by place, language, outcome and tool', function () {
    ['owner' => $owner, 'property' => $property] = aiUsageSetup();
    $property->settings->forceFill(['ai_monthly_cap_micros' => 10_000])->save();
    $lobby = AiThread::factory()->for($property)->create(['language' => 'ms']);
    $stay = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeStay, 'language' => null]);

    // 01:00 on 1 October in Kuala Lumpur, so October.
    $first = aiUsageTurn($lobby, ['cost_micros' => 2_000, 'created_at' => '2026-09-30 17:00:00']);
    $first->tools()->create(['tool' => AiTool::PropertyFacts, 'succeeded' => true]);
    aiUsageTurn($lobby, ['cost_micros' => 1_000, 'outcome' => AiOutcome::Escalated])->tools()->createMany([
        ['tool' => AiTool::PropertyFacts, 'succeeded' => false],
        ['tool' => AiTool::HandOver, 'succeeded' => true],
    ]);
    aiUsageTurn($stay, ['cost_micros' => 500]);
    // 23:00 on 30 September in Kuala Lumpur, and another property: both left out.
    aiUsageTurn($lobby, ['cost_micros' => 9_000, 'created_at' => '2026-09-30 15:00:00']);
    aiUsageTurn(AiThread::factory()->create(), ['cost_micros' => 9_000]);

    $this->actingAs($owner)->get(route('settings.ai-assistant.usage', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-assistant/usage')
            ->where('settings.month', '2026-10')
            ->where('settings.spentUsd', '0.00')
            ->where('settings.capPercent', 35)
            ->where('settings.turns', 3)
            ->where('settings.threads', 2)
            ->where('settings.places', [
                ['label' => 'Concierge lobby', 'turns' => 2, 'spentUsd' => '0.00'],
                ['label' => 'Concierge stay', 'turns' => 1, 'spentUsd' => '0.00'],
            ])
            ->where('settings.languages', fn ($rows): bool => collect($rows)->sortBy('language')->values()->all() === [
                ['language' => null, 'threads' => 1],
                ['language' => 'ms', 'threads' => 1],
            ])
            ->where('settings.outcomes.0', ['outcome' => ['value' => 'answered', 'label' => 'Answered', 'tone' => 'neutral'], 'turns' => 2])
            ->where('settings.outcomes.3.turns', 1)
            ->where('settings.tools', [
                ['label' => AiTool::PropertyFacts->label(), 'runs' => 2, 'failures' => 1],
                ['label' => AiTool::HandOver->label(), 'runs' => 1, 'failures' => 0],
            ])
            ->where('settings.bookings', []));
});

test('the usage tab lists bookings from quotes with their room total, and shows an earlier month', function () {
    ['unit' => $unit, 'property' => $property] = conciergeSetup();
    Feature::for($property)->activate(AiAssistant::class);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '150.00', 'extra_amount' => '0.00', 'supplement_amount' => '0.00', 'discount_amount' => '0.00', 'override_amount' => null, 'amount' => '150.00']);
    $owner = User::factory()->owner()->for($property)->create();
    $thread = AiThread::factory()->for($property)->create();
    aiUsageTurn($thread, ['outcome' => AiOutcome::Booked, 'reservation_id' => $unit->reservation_id, 'cost_micros' => 1_234_567, 'created_at' => '2026-09-10 08:00:00']);

    $this->actingAs($owner)->get(route('settings.ai-assistant.usage', ['property' => $property->code, 'month' => '2026-09']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.month', '2026-09')
            ->where('settings.spentUsd', '1.23')
            ->where('settings.bookings.0.reference', $unit->reservation->reference)
            ->where('settings.bookings.0.roomTotal', '300.00')
            ->where('settings.bookedRoomTotal', '300.00'));

    $this->actingAs($owner)->get(route('settings.ai-assistant.usage', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('settings.turns', 0)->where('settings.bookings', []));

    $this->actingAs($owner)->get(route('settings.ai-assistant.usage', ['property' => $property->code, 'month' => 'September']))
        ->assertSessionHasErrors('month');
});

/**
 * Log one turn of 10,000 input tokens on `gpt-6-luna`: 1,000 micros.
 */
function recordPricedAiTurn(AiThread $thread): AiTurn
{
    return app(RecordAiTurn::class)($thread->fresh(), 'gpt-6-luna', new TextUsage(inputTokens: 10_000), 100);
}

test('staff who manage settings are told once a month when spend reaches 80% of the cap', function () {
    Notification::fake();
    ['owner' => $owner, 'property' => $property] = aiUsageSetup();
    $frontDesk = User::factory()->for($property)->withPermissions([PermissionEnum::FrontDeskView])->create();
    $property->settings->forceFill(['ai_monthly_cap_micros' => 10_000])->save();
    $thread = AiThread::factory()->for($property)->create();
    aiUsageTurn($thread, ['cost_micros' => 6_000]);

    recordPricedAiTurn($thread);
    Notification::assertNothingSent();

    recordPricedAiTurn($thread);
    Notification::assertSentTo($owner, AiCapNearlyReached::class, fn (AiCapNearlyReached $message): bool => $message->spentUsd === '0.01' && $message->capUsd === '0.01');
    Notification::assertNotSentTo($frontDesk, AiCapNearlyReached::class);

    recordPricedAiTurn($thread);
    Notification::assertSentTimes(AiCapNearlyReached::class, 1);

    // 1 November in Kuala Lumpur: a new month, so a new notice once its own spend reaches 80%.
    $this->travelTo('2026-10-31 16:30:00');
    aiUsageTurn($thread, ['cost_micros' => 7_000]);
    recordPricedAiTurn($thread);
    Notification::assertSentTimes(AiCapNearlyReached::class, 2);
});

test('staff can turn off the cap notice email', function () {
    ['owner' => $owner] = aiUsageSetup();

    UserNotificationPreference::factory()->for($owner)->create([
        'type' => NotificationType::AiCapNearlyReached,
        'channel' => NotificationChannel::Mail,
        'enabled' => false,
    ]);

    expect(app(NotificationPreferences::class)->channelsFor($owner->refresh(), NotificationType::AiCapNearlyReached))->toBe(['database']);
});

test('no notice is sent without a cap', function () {
    Notification::fake();
    ['property' => $property] = aiUsageSetup();
    $thread = AiThread::factory()->for($property)->create();
    aiUsageTurn($thread, ['cost_micros' => 50_000]);

    recordPricedAiTurn($thread);

    Notification::assertNothingSent();
});

test('message text and handover summaries older than 90 days are purged, and the usage log stays', function () {
    $old = AiThread::factory()->create(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()->subDays(91), 'handover_summary' => 'Wants a refund.']);
    $recent = AiThread::factory()->create(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()->subDays(10), 'handover_summary' => 'Wants a late check-out.']);
    $turn = aiUsageTurn($old);
    $message = fn (AiThread $thread, string $at): string => tap((string) str()->uuid(), fn (string $id) => DB::table('agent_conversation_messages')->insert([
        'id' => $id, 'conversation_id' => $thread->agent_conversation_id, 'agent' => 'x', 'role' => 'user', 'content' => 'Hello',
        'attachments' => '[]', 'steps' => '[]', 'usage' => '{}', 'meta' => '{}', 'status' => 'done', 'created_at' => $at, 'updated_at' => $at,
    ]));
    $message($old, now()->subDays(91)->toDateTimeString());
    $recentMessage = $message($recent, now()->subDays(89)->toDateTimeString());

    expect(app(PurgeAiMessageText::class)())->toBe(1)
        ->and(app(PurgeAiMessageText::class)())->toBe(0);

    expect(DB::table('agent_conversation_messages')->pluck('id')->all())->toBe([$recentMessage])
        ->and($old->fresh()->handover_summary)->toBeNull()
        ->and($recent->fresh()->handover_summary)->toBe('Wants a late check-out.')
        ->and($turn->fresh())->not->toBeNull();
});

test('open threads with no message for 7 days close, and handed-over threads wait for staff', function () {
    $quiet = AiThread::factory()->create(['last_message_at' => now()->subDays(7)]);
    $active = AiThread::factory()->create(['last_message_at' => now()->subDays(6)]);
    $handedOver = AiThread::factory()->create(['status' => AiThreadStatus::Escalated, 'last_message_at' => now()->subDays(30)]);

    expect(app(CloseQuietAiThreads::class)())->toBe(1);

    expect($quiet->fresh()->status)->toBe(AiThreadStatus::Closed)
        ->and($active->fresh()->status)->toBe(AiThreadStatus::Open)
        ->and($handedOver->fresh()->status)->toBe(AiThreadStatus::Escalated);
});
