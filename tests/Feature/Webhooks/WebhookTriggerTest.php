<?php

require_once __DIR__.'/../Support/BookingSetup.php';
require_once __DIR__.'/../Support/MigratedStaySetup.php';

use App\Actions\FrontDesk\CancelUnits;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Webhooks\TriggerWebhook;
use App\Data\Reservations\NewBookingData;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\WebhookEvent;
use App\Jobs\BroadcastWebhookJob;
use App\Models\BusinessDate;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\RoomRevenuePolicy;
use App\Models\TransactionCode;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Database\Factories\BookingSourceFactory;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $setup
 */
function webhookBooking(array $setup, int $rooms = 1): BookingResult
{
    $line = ['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-10-20', 'departureDate' => '2026-10-22', 'adults' => 2];

    return app(CreateBooking::class)($setup['user'], $setup['property'], NewBookingData::from([
        'bookingSource' => BookingSourceFactory::directFor($setup['property']->id),
        'units' => array_fill(0, $rooms, $line),
        'guests' => [['guestId' => $setup['guest']->id, 'unitIndex' => 0]],
    ]));
}

/**
 * Run every queued BroadcastWebhookJob, as the queue worker would after the commit.
 */
function runWebhookBroadcasts(): void
{
    Queue::pushed(BroadcastWebhookJob::class)->each(fn (BroadcastWebhookJob $job) => app()->call([$job, 'handle']));
}

it('sends booking.created with the booking, its units and the booker contact', function () {
    $setup = bookingSetup();
    WebhookEndpoint::factory()->forProperty($setup['property'])->hearing([WebhookEvent::BookingCreated])->create();

    $reservation = webhookBooking($setup)->reservation;
    Queue::assertPushed(BroadcastWebhookJob::class, 1);
    runWebhookBroadcasts();

    $booking = WebhookDelivery::query()->sole()->payload['data']['booking'];
    expect($booking['reference'])->toBe($reservation->reference)
        ->and($booking['booker'])->toBe([
            'id' => $setup['guest']->id,
            'name' => 'Tan Wei Ming',
            'email' => $setup['guest']->email,
            'phone' => '+60123456789',
            'country_code' => $setup['guest']->country_code ?? $setup['guest']->nationality_code,
        ])
        ->and($booking['units'])->toHaveCount(1)
        ->and($booking['units'][0])->toMatchArray(['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22', 'adults' => 2])
        ->and($booking['total'])->toBe('200.00');
});

it('queues nothing when no endpoint hears the event', function () {
    $setup = bookingSetup();
    WebhookEndpoint::factory()->forProperty($setup['property'])->hearing([WebhookEvent::BookingCancelled])->create();
    WebhookEndpoint::factory()->forProperty(Property::factory()->create())->hearing([WebhookEvent::BookingCreated])->create();

    webhookBooking($setup);

    Queue::assertNotPushed(BroadcastWebhookJob::class);
});

it('sends one booking.cancelled when several units of a booking are cancelled', function () {
    $setup = bookingSetup();
    WebhookEndpoint::factory()->forProperty($setup['property'])->hearing([WebhookEvent::BookingCancelled])->create();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id]);
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::CancellationRevenue)->create();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();
    $reservation = webhookBooking($setup, rooms: 2)->reservation;

    $result = app(CancelUnits::class)($setup['user'], $reservation, $reservation->units()->pluck('id')->all(), doNotCharge: true, reason: 'Guest changed plans');

    expect($result->refusal)->toBeNull();
    Queue::assertPushed(BroadcastWebhookJob::class, fn (BroadcastWebhookJob $job): bool => $job->event === WebhookEvent::BookingCancelled && $job->subjectId === $reservation->id);
    Queue::assertPushed(BroadcastWebhookJob::class, 1);
});

it('sends nothing for a stay migrated from the old PMS', function () {
    $setup = migratedStaySetup();
    WebhookEndpoint::factory()->hearing([WebhookEvent::BookingCreated, WebhookEvent::StayCheckedIn, WebhookEvent::PaymentReceived])->create();

    $result = createMigratedStay($setup);

    expect($result->refusal)->toBeNull();
    Queue::assertNotPushed(BroadcastWebhookJob::class);
});

it('sends nothing while a wastays command runs', function () {
    $property = Property::factory()->create();
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    WebhookEndpoint::factory()->hearing([WebhookEvent::GuestCreated])->create();

    event(new CommandStarting('wastays:import-guests', new ArrayInput([]), new NullOutput));
    app(TriggerWebhook::class)(WebhookEvent::GuestCreated, $property->id, Guest::class, $guest->id);
    Context::forgetHidden(TriggerWebhook::MUTED);

    Queue::assertNotPushed(BroadcastWebhookJob::class);
});

it('sends only the id of an anonymized guest and both ids of a merge', function () {
    $property = Property::factory()->create();
    $kept = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aina Rahman']);
    $removed = Guest::factory()->create(['property_id' => $property->id]);
    $anonymized = Guest::factory()->create(['property_id' => $property->id, 'anonymized_at' => now()]);
    WebhookEndpoint::factory()->hearing([WebhookEvent::GuestMerged, WebhookEvent::GuestAnonymized])->create();

    app(TriggerWebhook::class)(WebhookEvent::GuestMerged, $property->id, Guest::class, $kept->id, ['removed_guest_id' => $removed->id]);
    app(TriggerWebhook::class)(WebhookEvent::GuestAnonymized, $property->id, Guest::class, $anonymized->id);
    runWebhookBroadcasts();

    $payloads = WebhookDelivery::query()->get()->mapWithKeys(fn (WebhookDelivery $delivery): array => [$delivery->event->value => $delivery->payload['data']]);
    expect($payloads['guest.anonymized'])->toBe(['guest' => ['id' => $anonymized->id]])
        ->and($payloads['guest.merged']['removed_guest_id'])->toBe($removed->id)
        ->and(array_keys($payloads['guest.merged']['guest']))->toBe(['id', 'name', 'email', 'phone', 'country_code']);
});

it('sends an event once per subject in one request', function () {
    $property = Property::factory()->create();
    $reservationId = 991;
    WebhookEndpoint::factory()->hearing([WebhookEvent::BookingUpdated])->create();

    app(TriggerWebhook::class)(WebhookEvent::BookingUpdated, $property->id, Reservation::class, $reservationId);
    app(TriggerWebhook::class)(WebhookEvent::BookingUpdated, $property->id, Reservation::class, $reservationId);

    Queue::assertPushed(BroadcastWebhookJob::class, 1);
});
