<?php

use App\Actions\Webhooks\BroadcastWebhook;
use App\Actions\Webhooks\DeliverWebhook;
use App\Actions\Webhooks\PruneWebhookDeliveries;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Property;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookEndpointDisabled;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('queues one delivery per endpoint that hears the event at the property', function () {
    Queue::fake();
    $property = Property::factory()->create();
    $own = WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCreated])->create();
    $everyProperty = WebhookEndpoint::factory()->hearing([WebhookEvent::BookingCreated])->create();
    WebhookEndpoint::factory()->forProperty(Property::factory()->create())->hearing([WebhookEvent::BookingCreated])->create();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCancelled])->create();
    WebhookEndpoint::factory()->forProperty($property)->off()->hearing([WebhookEvent::BookingCreated])->create();

    $queued = app(BroadcastWebhook::class)(WebhookEvent::BookingCreated, $property, ['booking' => ['code' => 'B-1']]);

    $deliveries = WebhookDelivery::query()->orderBy('webhook_endpoint_id')->get();
    expect($queued)->toBe(2)
        ->and($deliveries->pluck('webhook_endpoint_id')->all())->toBe([$own->id, $everyProperty->id])
        ->and($deliveries->pluck('event_id')->unique())->toHaveCount(1)
        ->and($deliveries->first()->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($deliveries->first()->payload)->toMatchArray([
            'event' => 'booking.created',
            'version' => 1,
            'test' => false,
            'data' => ['booking' => ['code' => 'B-1']],
        ])
        ->and($deliveries->first()->payload['property']['code'])->toBe($property->code);
    Queue::assertPushed(DeliverWebhookJob::class, 2);
});

it('sends nothing when the transaction rolls back', function () {
    Queue::fake();
    $property = Property::factory()->create();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCreated])->create();

    try {
        DB::transaction(function () use ($property): void {
            app(BroadcastWebhook::class)(WebhookEvent::BookingCreated, $property, []);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('posts the signed body and marks the delivery delivered', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $endpoint = WebhookEndpoint::factory()->create(['failing_since' => now()->subDay()]);
    $delivery = WebhookDelivery::factory()->for($endpoint, 'endpoint')->create();

    $status = app(DeliverWebhook::class)($delivery);

    expect($status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->tries)->toHaveCount(1)
        ->and($endpoint->refresh()->failing_since)->toBeNull();

    Http::assertSent(function (Request $request) use ($endpoint, $delivery): bool {
        $timestamp = (int) $request->header('X-Estatell-Timestamp')[0];

        return $request->url() === $endpoint->url
            && $request->header('X-Estatell-Event')[0] === 'booking.created'
            && $request->header('X-Estatell-Delivery')[0] === $delivery->event_id
            && json_decode($request->body(), true) == $delivery->payload
            && app(WebhookSignature::class)->matches($endpoint->secret, $timestamp, $request->body(), $request->header('X-Estatell-Signature')[0]);
    });
});

it('refuses on a 4xx without another try', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('gone', 410)]);
    $delivery = WebhookDelivery::factory()->create();

    $status = app(DeliverWebhook::class)($delivery);

    expect($status)->toBe(WebhookDeliveryStatus::Refused)
        ->and($delivery->refresh()->response_status)->toBe(410)
        ->and($delivery->error)->toBe('HTTP 410')
        ->and($delivery->endpoint->failing_since)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('retries a 5xx, a 429 and a timeout with backoff', function (Closure $response) {
    Queue::fake();
    Http::fake(['*' => $response]);
    $delivery = WebhookDelivery::factory()->create();

    $status = app(DeliverWebhook::class)($delivery);

    expect($status)->toBe(WebhookDeliveryStatus::Retrying)
        ->and($delivery->refresh()->next_attempt_at->equalTo(now()->addMinute()))->toBeTrue();
    Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->webhookDeliveryId === $delivery->id && $job->delay !== null);
})->with([
    '500' => [fn () => fn () => Http::response('error', 500)],
    '429' => [fn () => fn () => Http::response('slow down', 429)],
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
]);

it('fails after the last try', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('error', 503)]);
    $delivery = WebhookDelivery::factory()->create(['status' => WebhookDeliveryStatus::Retrying, 'attempts' => WebhookDelivery::MAX_ATTEMPTS - 1]);

    $status = app(DeliverWebhook::class)($delivery);

    expect($status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->refresh()->attempts)->toBe(WebhookDelivery::MAX_ATTEMPTS)
        ->and($delivery->next_attempt_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does not send a delivery that is finished or whose endpoint is off', function () {
    Http::fake();
    $delivered = WebhookDelivery::factory()->create(['status' => WebhookDeliveryStatus::Delivered]);
    $off = WebhookDelivery::factory()->for(WebhookEndpoint::factory()->off(), 'endpoint')->create();

    expect(app(DeliverWebhook::class)($delivered))->toBe(WebhookDeliveryStatus::Delivered)
        ->and(app(DeliverWebhook::class)($off))->toBe(WebhookDeliveryStatus::Failed)
        ->and($off->refresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('turns an endpoint off after 3 days of failures and tells platform admins', function () {
    Queue::fake();
    Notification::fake();
    Http::fake(['*' => Http::response('not found', 404)]);
    $admin = User::factory()->admin()->create();
    $endpoint = WebhookEndpoint::factory()->create(['failing_since' => now()->subDays(3)]);
    $delivery = WebhookDelivery::factory()->for($endpoint, 'endpoint')->create();

    app(DeliverWebhook::class)($delivery);

    expect($endpoint->refresh()->is_active)->toBeFalse()
        ->and($endpoint->disabled_at)->not->toBeNull()
        ->and($endpoint->disabled_reason)->toBe('HTTP 404 for 3 days');
    Notification::assertSentTo($admin, WebhookEndpointDisabled::class);
});

it('keeps an endpoint on while it has failed for less than 3 days', function () {
    Queue::fake();
    Notification::fake();
    Http::fake(['*' => Http::response('error', 404)]);
    User::factory()->admin()->create();
    $endpoint = WebhookEndpoint::factory()->create(['failing_since' => now()->subDays(2)]);

    app(DeliverWebhook::class)(WebhookDelivery::factory()->for($endpoint, 'endpoint')->create());

    expect($endpoint->refresh()->is_active)->toBeTrue()
        ->and($endpoint->failing_since->equalTo(now()->subDays(2)))->toBeTrue();
    Notification::assertNothingSent();
});

it('prunes deliveries older than 30 days with their tries', function () {
    $old = WebhookDelivery::factory()->create(['created_at' => now()->subDays(31)]);
    $old->tries()->create(['attempt' => 1, 'response_status' => 200, 'duration_ms' => 12, 'attempted_at' => now()->subDays(31)]);
    $recent = WebhookDelivery::factory()->create(['created_at' => now()->subDays(29)]);

    expect(app(PruneWebhookDeliveries::class)())->toBe(1)
        ->and(app(PruneWebhookDeliveries::class)())->toBe(0)
        ->and(WebhookDelivery::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(DB::table('webhook_delivery_attempts')->count())->toBe(0);
});
