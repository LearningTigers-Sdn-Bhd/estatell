<?php

use App\Actions\Concierge\SendConciergeStayLink;
use App\Data\Webhooks\WebhookDeliveryData;
use App\Enums\PasscodeStatus;
use App\Enums\WebhookEvent;
use App\Events\StayCheckedIn;
use App\Features\Concierge;
use App\Jobs\BroadcastWebhookJob;
use App\Jobs\SendStayWelcomeJob;
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

it('holds the welcome while the PIN is pending, then sends it with the PIN', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    $pin = welcomePin($unit, PasscodeStatus::Pending);

    $job = (new SendStayWelcomeJob($unit->property_id, $unit->id))->withFakeQueueInteractions();
    $job->handle(app(KioskPin::class), app(SendConciergeStayLink::class));

    $job->assertReleased(5);
    Notification::assertNothingSent();

    $pin->forceFill(['status' => PasscodeStatus::Active, 'code' => '482913'])->save();
    $job->handle(app(KioskPin::class), app(SendConciergeStayLink::class));

    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === '482913');
});

it('stops waiting after the hold and sends without the PIN', function () {
    Notification::fake();
    ['unit' => $unit] = welcomeStay();
    welcomePin($unit, PasscodeStatus::Pending);

    $job = (new SendStayWelcomeJob($unit->property_id, $unit->id))->withFakeQueueInteractions();
    $this->travel(SendStayWelcomeJob::HOLD_SECONDS + 1)->seconds();
    $job->handle(app(KioskPin::class), app(SendConciergeStayLink::class));

    Notification::assertSentOnDemand(ConciergeStayLink::class, fn (ConciergeStayLink $mail) => $mail->pinCode === null && $mail->pinOnStayPage === true);
});

it('queues the welcome job when a unit checks in', function () {
    Queue::fake();
    ['property' => $property, 'unit' => $unit] = welcomeStay();

    StayCheckedIn::dispatch($property->id, $unit->id);

    Queue::assertPushed(SendStayWelcomeJob::class, fn (SendStayWelcomeJob $job) => $job->reservationUnitId === $unit->id);
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
