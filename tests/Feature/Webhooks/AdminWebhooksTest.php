<?php

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Property;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookSampleData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function webhookAdmin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

it('is for platform admins only', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('admin.webhooks.index'))->assertNotFound();
    $this->actingAs($staff)->post(route('admin.webhooks.store'), [])->assertNotFound();
});

it('lists every endpoint without its secret', function () {
    $property = Property::factory()->create(['name' => 'Sand Bay']);
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCreated, WebhookEvent::StayCheckedIn])->create(['name' => 'A relay']);
    WebhookEndpoint::factory()->off()->create(['name' => 'B owners', 'disabled_at' => now(), 'disabled_reason' => 'HTTP 404 for 3 days']);

    $this->actingAs(webhookAdmin())
        ->get(route('admin.webhooks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/webhooks/index')
            ->has('endpoints', 2)
            ->where('endpoints.0.propertyName', 'Sand Bay')
            ->where('endpoints.0.events', ['booking.created', 'stay.checked_in'])
            ->missing('endpoints.0.secret')
            ->where('endpoints.1.disabledReason', 'HTTP 404 for 3 days')
            ->has('events', count(WebhookEvent::cases()))
        );
});

it('adds an endpoint, keeps the secret encrypted and shows it once', function () {
    $admin = webhookAdmin();

    $response = $this->actingAs($admin)->post(route('admin.webhooks.store'), [
        'name' => 'n8n – WhatsApp',
        'url' => 'https://n8n.example.test/webhook/abc',
        'property_id' => null,
        'events' => ['booking.created', 'booking.cancelled', 'booking.created'],
        'is_active' => true,
    ]);

    $endpoint = WebhookEndpoint::query()->sole();
    $response->assertRedirect(route('admin.webhooks.show', $endpoint))
        ->assertInertiaFlash('webhookSecret.secret', $endpoint->secret);

    expect($endpoint->secret)->toStartWith('whsec_')
        ->and(DB::table('webhook_endpoints')->value('secret'))->not->toBe($endpoint->secret)
        ->and($endpoint->property_id)->toBeNull()
        ->and($endpoint->events()->pluck('event')->map->value->sort()->values()->all())->toBe(['booking.cancelled', 'booking.created'])
        ->and(Activity::query()->where('event', 'webhook_endpoint.created')->count())->toBe(1);
});

it('refuses a plain http URL and an endpoint with no events', function () {
    $this->actingAs(webhookAdmin())
        ->post(route('admin.webhooks.store'), ['name' => 'Relay', 'url' => 'http://relay.example.test', 'events' => []])
        ->assertSessionHasErrors(['url', 'events']);

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('changes the events an endpoint hears', function () {
    $endpoint = WebhookEndpoint::factory()->hearing([WebhookEvent::BookingCreated, WebhookEvent::GuestCreated])->create();

    $this->actingAs(webhookAdmin())
        ->put(route('admin.webhooks.update', $endpoint), ['name' => 'Renamed', 'url' => $endpoint->url, 'events' => ['guest.created', 'room.cleaned']])
        ->assertSessionHasNoErrors();

    expect($endpoint->refresh()->name)->toBe('Renamed')
        ->and($endpoint->events()->pluck('event')->map->value->sort()->values()->all())->toBe(['guest.created', 'room.cleaned']);
});

it('turns a disabled endpoint on and starts the failure count again', function () {
    $endpoint = WebhookEndpoint::factory()->off()->create(['failing_since' => now()->subDays(4), 'disabled_at' => now()->subDay(), 'disabled_reason' => 'HTTP 500 for 3 days']);

    $this->actingAs(webhookAdmin())
        ->put(route('admin.webhooks.active.update', $endpoint), ['active' => true])
        ->assertInertiaFlash('toast.type', 'success');

    expect($endpoint->refresh()->is_active)->toBeTrue()
        ->and($endpoint->failing_since)->toBeNull()
        ->and($endpoint->disabled_at)->toBeNull()
        ->and($endpoint->disabled_reason)->toBeNull();
});

it('rotates the secret and shows the new one once', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    $old = $endpoint->secret;

    $this->actingAs(webhookAdmin())
        ->post(route('admin.webhooks.rotate-secret', $endpoint))
        ->assertRedirect(route('admin.webhooks.show', $endpoint))
        ->assertInertiaFlash('webhookSecret.secret', $endpoint->refresh()->secret);

    expect($endpoint->secret)->not->toBe($old);
});

it('deletes an endpoint with its deliveries', function () {
    $endpoint = WebhookEndpoint::factory()->hearing([WebhookEvent::BookingCreated])->create();
    WebhookDelivery::factory()->for($endpoint, 'endpoint')->create();

    $this->actingAs(webhookAdmin())
        ->delete(route('admin.webhooks.destroy', $endpoint))
        ->assertRedirect(route('admin.webhooks.index'));

    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});

it('sends a test event with sample data, also while the endpoint is off', function () {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->off()->hearing([WebhookEvent::BookingCreated])->create();

    $this->actingAs(webhookAdmin())
        ->post(route('admin.webhooks.test', $endpoint), ['event' => 'booking.created'])
        ->assertInertiaFlash('toast.type', 'success');

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->is_test)->toBeTrue()
        ->and($delivery->payload['test'])->toBeTrue()
        ->and($delivery->payload['data']['booking']['reference'])->toBe('TEST-0001');
    Queue::assertPushed(DeliverWebhookJob::class, 1);
});

it('refuses a test event the endpoint does not hear', function () {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->hearing([WebhookEvent::BookingCreated])->create();

    $this->actingAs(webhookAdmin())
        ->post(route('admin.webhooks.test', $endpoint), ['event' => 'guest.created'])
        ->assertInertiaFlash('toast.type', 'error');

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('has sample data for every event', function () {
    foreach (WebhookEvent::cases() as $event) {
        expect(app(WebhookSampleData::class)->for($event))->not->toBeEmpty();
    }
});

it('retries a failed delivery and refuses a delivered one', function () {
    Queue::fake();
    $failed = WebhookDelivery::factory()->failed()->create();
    $delivered = WebhookDelivery::factory()->create(['status' => WebhookDeliveryStatus::Delivered]);
    $admin = webhookAdmin();

    $this->actingAs($admin)->post(route('admin.webhooks.deliveries.retry', $failed))->assertInertiaFlash('toast.type', 'success');
    $this->actingAs($admin)->post(route('admin.webhooks.deliveries.retry', $delivered))->assertInertiaFlash('toast.type', 'error');

    expect($failed->refresh()->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivered->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered);
    Queue::assertPushed(DeliverWebhookJob::class, 1);
});

it('shows an endpoint with its deliveries and the open delivery', function () {
    $endpoint = WebhookEndpoint::factory()->hearing([WebhookEvent::BookingCreated])->create();
    $delivery = WebhookDelivery::factory()->for($endpoint, 'endpoint')->create(['status' => WebhookDeliveryStatus::Delivered, 'attempts' => 1, 'delivered_at' => now()]);
    $delivery->tries()->create(['attempt' => 1, 'response_status' => 200, 'duration_ms' => 41, 'attempted_at' => now()]);
    WebhookDelivery::factory()->create();

    $this->actingAs(webhookAdmin())
        ->get(route('admin.webhooks.show', ['webhookEndpoint' => $endpoint, 'delivery' => $delivery->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/webhooks/show')
            ->where('stats.sent', 1)
            ->where('stats.failed', 0)
            ->has('deliveries.data', 1)
            ->where('selectedDelivery.eventId', $delivery->event_id)
            ->has('selectedDelivery.tries', 1)
        );
});

it('lists deliveries across endpoints with filters', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->for($endpoint, 'endpoint')->failed()->create();
    WebhookDelivery::factory()->create(['status' => WebhookDeliveryStatus::Delivered]);

    $this->actingAs(webhookAdmin())
        ->get(route('admin.webhooks.deliveries', ['filter' => ['status' => 'failed']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/webhooks/deliveries')
            ->has('deliveries.data', 1)
            ->where('deliveries.data.0.endpointId', $endpoint->id)
            ->where('deliveries.data.0.canRetry', true)
        );
});
