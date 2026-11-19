<?php

use App\Actions\Concierge\SendConciergeStayLink;
use App\Actions\DoorLocks\SyncStayCards;
use App\Actions\DoorLocks\SyncStayPasscodes;
use App\Data\Webhooks\WebhookDeliveryData;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Enums\WebhookEvent;
use App\Events\StayCheckedIn;
use App\Features\Concierge;
use App\Jobs\BroadcastWebhookJob;
use App\Jobs\SendStayWelcomeJob;
use App\Jobs\SyncStayPasscodesJob;
use App\Models\DoorPasscode;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\ConciergeStayLink;
use App\Services\Kiosk\KioskPin;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/DoorLockSetup.php';

/**
 * A checked-in stay in room 101 with a TTLock lock, a guest with an email address and the Concierge feature on. The clock is
 * moved to the stay's night, as the PIN reads only a PIN that has not ended.
 *
 * @return array{property: Property, unit: ReservationUnit}
 */
function welcomeStay(): array
{
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    test()->travelTo('2026-10-14 12:00:00');
    $unit->forceFill(['checked_in_at' => now()->subSeconds(10)])->save();
    $unit->reservation->booker->forceFill(['email' => 'aisha@example.test'])->save();
    Feature::for($property)->activate(Concierge::class);

    return compact('property', 'unit');
}

function welcomePin(ReservationUnit $unit, PasscodeStatus $status, string $code = '482913'): DoorPasscode
{
    return DoorPasscode::factory()->create([
        'door_lock_id' => $unit->property->doorLocks()->firstOrFail()->id,
        'reservation_unit_id' => $unit->id,
        'status' => $status,
        'code' => $status === PasscodeStatus::Active ? $code : null,
        'ends_at' => now()->addDays(2),
    ]);
}

function sendWelcome(ReservationUnit $unit): void
{
    app(SendConciergeStayLink::class)($unit->property_id, $unit->id, app(KioskPin::class)->for($unit->fresh()));
}

it('puts the door PIN, room and end time in the one email when the PIN is ready', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    welcomePin($unit, PasscodeStatus::Active);

    sendWelcome($unit);

    Notification::assertSentOnDemandTimes(ConciergeStayLink::class, 1);
    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === '482913'
        && $mail->roomNumber === '101'
        && $mail->pinValidUntil !== null
        && $mail->pinOnStayPage === false);
});

it('sends the email without a PIN, and says it is on the stay page, when the PIN failed', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    welcomePin($unit, PasscodeStatus::Failed);

    sendWelcome($unit);

    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === null && $mail->pinOnStayPage === true);
});

it('sends a stay with no lock at once and without a PIN line', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    $unit->nights()->update(['room_id' => null]);

    SendStayWelcomeJob::dispatchSync($unit->property_id, $unit->id);

    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === null && $mail->pinOnStayPage === false);
});

/** Asks for the welcome the way the check-in and every end of a PIN sync do. */
function askWelcome(ReservationUnit $unit): void
{
    SendStayWelcomeJob::dispatchSync($unit->property_id, $unit->id);
}

/** Asks for the welcome the way the deadline of the stay's check-in does. */
function askWelcomeAtDeadline(ReservationUnit $unit, ?int $checkedInAt = null): void
{
    SendStayWelcomeJob::dispatchSync($unit->property_id, $unit->id, $checkedInAt ?? $unit->fresh()->checked_in_at->getTimestamp());
}

it('holds the welcome while the PIN is pending, then sends it once with the PIN', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    $pin = welcomePin($unit, PasscodeStatus::Pending);

    askWelcome($unit);

    Notification::assertNothingSent();
    expect($unit->fresh()->welcome_sent_at)->toBeNull();

    $pin->forceFill(['status' => PasscodeStatus::Active, 'code' => '482913'])->save();
    askWelcome($unit);
    askWelcome($unit);

    Notification::assertSentOnDemandTimes(ConciergeStayLink::class, 1);
    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === '482913' && $mail->pinOnStayPage === false);
    expect($unit->fresh()->welcome_sent_at)->not->toBeNull();
});

it('holds the welcome while the PIN failed and is being tried again, and does not send it without the PIN', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    welcomePin($unit, PasscodeStatus::Failed);

    askWelcome($unit);

    Notification::assertNothingSent();
});

it('sends the welcome without the PIN at the deadline, says so in the log, and sends nothing more when the PIN comes later', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    $pin = welcomePin($unit, PasscodeStatus::Failed);

    askWelcomeAtDeadline($unit);

    Notification::assertSentOnDemandTimes(ConciergeStayLink::class, 1);
    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === null && $mail->pinOnStayPage === true);
    expect(Activity::query()->where('event', 'door-locks.welcome-without-pin')->sole()->subject_id)->toBe($unit->id);

    $pin->forceFill(['status' => PasscodeStatus::Active, 'code' => '482913'])->save();
    askWelcome($unit);
    askWelcomeAtDeadline($unit);

    Notification::assertSentOnDemandTimes(ConciergeStayLink::class, 1);
});

it('sends the welcome at the deadline with the PIN when the PIN is ready, and logs nothing', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    welcomePin($unit, PasscodeStatus::Active);

    askWelcomeAtDeadline($unit);

    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === '482913');
    expect(Activity::query()->where('event', 'door-locks.welcome-without-pin')->count())->toBe(0);
});

it('does not let the deadline of an earlier check-in cut the wait of a later one short', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    welcomePin($unit, PasscodeStatus::Pending);
    $first = $unit->fresh()->checked_in_at->getTimestamp();
    $unit->forceFill(['checked_in_at' => now()->addMinutes(5)])->save();

    askWelcomeAtDeadline($unit, $first);

    Notification::assertNothingSent();
});

it('sends a stay with no PIN to wait for at once, once', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    $unit->nights()->update(['room_id' => null]);

    askWelcome($unit);
    askWelcome($unit);
    askWelcomeAtDeadline($unit);

    Notification::assertSentOnDemandTimes(ConciergeStayLink::class, 1);
    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === null && $mail->pinOnStayPage === false);
    expect(Activity::query()->where('event', 'door-locks.welcome-without-pin')->count())->toBe(0);
});

it('welcomes a stay that is checked in again after an undo, and not twice for the same check-in', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    $unit->nights()->update(['room_id' => null]);

    askWelcome($unit);
    $this->travel(1)->minute();
    $unit->forceFill(['checked_in_at' => now()])->save();
    askWelcome($unit);
    askWelcome($unit);

    Notification::assertSentOnDemandTimes(ConciergeStayLink::class, 2);
});

it('sends nothing for a stay that is not checked in any more', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    $unit->nights()->update(['room_id' => null]);
    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();

    askWelcome($unit);

    Notification::assertNothingSent();
});

it('queues the welcome when a unit checks in, and its deadline for that check-in', function () {
    Queue::fake();
    ['property' => $property, 'unit' => $unit] = welcomeStay();

    StayCheckedIn::dispatch($property->id, $unit->id);

    $checkedInAt = $unit->fresh()->checked_in_at->getTimestamp();
    Queue::assertPushed(SendStayWelcomeJob::class, 2);
    Queue::assertPushed(SendStayWelcomeJob::class, fn (SendStayWelcomeJob $job) => $job->reservationUnitId === $unit->id && $job->deadlineFor === null);
    Queue::assertPushed(SendStayWelcomeJob::class, fn (SendStayWelcomeJob $job) => $job->deadlineFor === $checkedInAt
        && $job->delay !== null && now()->diffInSeconds($job->delay, true) >= SendStayWelcomeJob::DEADLINE_SECONDS - 5);
});

it('asks for the welcome again when a PIN sync ends, so a PIN that is ready is sent at once', function () {
    Queue::fake();
    ['unit' => $unit] = welcomeStay();
    fakeTtlock();

    (new SyncStayPasscodesJob($unit->id))->handle(app(SyncStayPasscodes::class), app(SyncStayCards::class));

    Queue::assertPushed(SendStayWelcomeJob::class, fn (SendStayWelcomeJob $job) => $job->reservationUnitId === $unit->id && $job->deadlineFor === null);
});

it('sends stay.welcome_ready with the PIN, the link and the guest phone to an endpoint that hears it', function () {
    Queue::fake();
    Notification::fake();
    ['property' => $property, 'unit' => $unit] = welcomeStay();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::StayWelcomeReady])->create();
    welcomePin($unit, PasscodeStatus::Active);

    sendWelcome($unit);

    Queue::assertPushed(BroadcastWebhookJob::class, function (BroadcastWebhookJob $job) {
        $stay = $job->context['stay'];

        return $job->event === WebhookEvent::StayWelcomeReady
            && $stay['pin']['code'] === '482913'
            && $stay['pin']['valid_until_text'] === now()->addDays(2)->setTimezone('Asia/Kuala_Lumpur')->isoFormat('D MMM YYYY, h:mm A')
            && $stay['room'] === '101'
            && str_starts_with($stay['url'], url('/c/'))
            && array_key_exists('phone', $stay['guest']);
    });

    $job = Queue::pushed(BroadcastWebhookJob::class)->first();
    $data = app($job->event->payloadBuilder())->build($job->event, $unit, $job->context);

    expect($data['unit_id'])->toBe($unit->id)->and($data['stay']['pin']['code'])->toBe('482913');
});

it('hides the door PIN and the link token in the delivery Sheet, and keeps them in the stored body', function () {
    $stay = ['url' => url('/c/secret-token-123'), 'room' => '101', 'guest' => null, 'pin' => ['code' => '482913', 'valid_until' => '2026-10-16T12:00:00+08:00', 'valid_until_text' => '16 Oct 2026, 12:00 PM']];
    $delivery = WebhookDelivery::factory()->create(['event' => WebhookEvent::StayWelcomeReady, 'payload' => ['data' => ['stay' => $stay]]]);

    $shown = WebhookDeliveryData::fromModel($delivery->load(WebhookDeliveryData::RELATIONS))->payload;

    expect($shown)->not->toContain('482913')->not->toContain('secret-token-123')->toContain('/c/••••••••')
        ->and($delivery->fresh()->payload['data']['stay']['pin']['code'])->toBe('482913');
});

it('queues the webhook broadcast encrypted, as it can hold a door PIN', function () {
    expect(BroadcastWebhookJob::class)->toImplement(ShouldBeEncrypted::class);
});

it('still sends the webhook when the guest has no email, and sends no email', function () {
    Queue::fake();
    Notification::fake();
    ['property' => $property, 'unit' => $unit] = welcomeStay();
    $unit->reservation->booker->forceFill(['email' => null])->save();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::StayWelcomeReady])->create();

    sendWelcome($unit);

    Notification::assertNothingSent();
    Queue::assertPushed(BroadcastWebhookJob::class, fn (BroadcastWebhookJob $job) => $job->event === WebhookEvent::StayWelcomeReady && $job->context['stay']['pin'] === null);
});
