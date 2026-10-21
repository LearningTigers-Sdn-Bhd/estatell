<?php

use App\Actions\Channels\ActivateChannel;
use App\Actions\Channels\DisconnectChannel;
use App\Actions\Channels\ImportFutureBookings;
use App\Actions\Channels\PauseChannel;
use App\Enums\ChannelConnectionStatus;
use App\Features\ChannelManager;
use App\Models\ChannelBookingRevision;
use App\Models\ChannelConnection;
use App\Models\ChannelOtaMapping;
use App\Models\ChannelProperty;
use App\Models\OtaChannel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ChannelStructure.php';

/**
 * A synced property with a Booking.com connection in the given status. A connection that is not a draft has one mapped line.
 */
function lifecycleConnection(ChannelConnectionStatus $status, ?OtaChannel $ota = null): ChannelConnection
{
    $number = ChannelProperty::query()->count() + 1;
    ['property' => $property, 'channelProperty' => $channelProperty] = channelAriSetup(externalId: "prop-{$number}");
    Feature::for($property)->activate(ChannelManager::class);
    $connection = ChannelConnection::factory()->create([
        'channel_property_id' => $channelProperty->id,
        'ota_channel_id' => ($ota ?? OtaChannel::query()->where('code', 'booking_com')->first() ?? bookingChannel())->id,
        'external_channel_id' => "channel-{$number}",
        'status' => $status,
        'activated_at' => $status === ChannelConnectionStatus::Paused ? now()->subDay() : null,
    ]);

    if ($status !== ChannelConnectionStatus::Draft) {
        ChannelOtaMapping::factory()->create(['channel_connection_id' => $connection->id, 'channel_rate_plan_mapping_id' => $channelProperty->ratePlanMappings()->firstOrFail()->id]);
    }

    return $connection;
}

function channelAdminUser(): User
{
    return User::factory()->admin()->create();
}

function channexPaths(string $method): array
{
    return collect(channexCalls())->filter(fn ($request): bool => $request->method() === $method)->map(fn ($request): string => parse_url($request->url(), PHP_URL_PATH))->values()->all();
}

test('activating a mapped connection starts it at Channex and records when', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Mapped);
    fakeChannexMapping();

    $result = app(ActivateChannel::class)(channelAdminUser(), $connection);

    expect($result->ok)->toBeTrue()->and($result->message)->toBe('Booking.com is active. Channex is sending it availability and rates.')
        ->and(channexPaths('POST'))->toBe(['/api/v1/channels/channel-1/activate'])
        ->and($connection->refresh()->status)->toBe(ChannelConnectionStatus::Active)
        ->and($connection->activated_at)->not->toBeNull();
    expect(Activity::query()->where('event', 'channel.activated')->count())->toBe(1);
});

test('a connection with no mapping, no map step or already running is not activated', function () {
    fakeChannexMapping();
    $draft = lifecycleConnection(ChannelConnectionStatus::Draft);
    $active = lifecycleConnection(ChannelConnectionStatus::Active);
    $mappedWithoutLines = lifecycleConnection(ChannelConnectionStatus::Mapped);
    $mappedWithoutLines->otaMappings()->delete();

    expect(app(ActivateChannel::class)(channelAdminUser(), $draft)->message)->toBe('Booking.com cannot be started now. Map its rooms and rates first.')
        ->and(app(ActivateChannel::class)(channelAdminUser(), $active)->ok)->toBeFalse()
        ->and(app(ActivateChannel::class)(channelAdminUser(), $mappedWithoutLines)->message)->toBe('Map at least one rate before you start Booking.com.');
    expect(channexPaths('POST'))->toBe([]);
});

test('a refused or unreachable activation leaves the connection as it was', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Mapped);
    Http::fake(['*' => Http::response(['errors' => ['code' => 'validation_error']], 422)]);

    $refused = app(ActivateChannel::class)(channelAdminUser(), $connection);
    expect($refused->ok)->toBeFalse()->and($refused->retryable)->toBeFalse()->and($refused->message)->toBe('Channex refused to start Booking.com.');

    resetChannexFake();
    Http::fake(['*' => Http::response([], 429)]);

    expect(app(ActivateChannel::class)(channelAdminUser(), $connection)->retryable)->toBeTrue()
        ->and($connection->refresh()->status)->toBe(ChannelConnectionStatus::Mapped)
        ->and($connection->activated_at)->toBeNull();
});

test('pausing stops the connection and starting it again keeps the first start date', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Active);
    $connection->update(['activated_at' => now()->subDays(3)]);
    $first = $connection->activated_at;
    fakeChannexMapping();

    expect(app(PauseChannel::class)(channelAdminUser(), $connection)->ok)->toBeTrue()
        ->and($connection->refresh()->status)->toBe(ChannelConnectionStatus::Paused)
        ->and(channexPaths('POST'))->toBe(['/api/v1/channels/channel-1/deactivate']);

    expect(app(ActivateChannel::class)(channelAdminUser(), $connection)->ok)->toBeTrue()
        ->and($connection->refresh()->status)->toBe(ChannelConnectionStatus::Active)
        ->and($connection->activated_at->equalTo($first))->toBeTrue();
    expect(Activity::query()->where('event', 'channel.paused')->count())->toBe(1);
});

test('only an active connection can be paused', function () {
    $mapped = lifecycleConnection(ChannelConnectionStatus::Mapped);
    fakeChannexMapping();

    expect(app(PauseChannel::class)(channelAdminUser(), $mapped)->message)->toBe('Booking.com is not active.');
    expect(channexPaths('POST'))->toBe([]);
});

test('disconnecting a running connection stops it, removes it at Channex and keeps its bookings', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Active);
    $revision = ChannelBookingRevision::factory()->create(['channel_property_id' => $connection->channel_property_id, 'channel_connection_id' => $connection->id]);
    fakeChannexMapping();

    $result = app(DisconnectChannel::class)(channelAdminUser(), $connection);

    expect($result->ok)->toBeTrue()->and($result->message)->toBe('Booking.com disconnected.')
        ->and(collect(channexCalls())->map(fn ($request): string => $request->method().' '.parse_url($request->url(), PHP_URL_PATH))->all())
        ->toBe(['POST /api/v1/channels/channel-1/deactivate', 'DELETE /api/v1/channels/channel-1'])
        ->and(ChannelConnection::query()->count())->toBe(0)
        ->and(ChannelOtaMapping::query()->count())->toBe(0)
        ->and($revision->refresh()->channel_connection_id)->toBeNull();
    expect(Activity::query()->where('event', 'channel.disconnected')->count())->toBe(1);
});

test('a draft is removed without stopping it, and a connection Channex no longer has is forgotten', function () {
    $draft = lifecycleConnection(ChannelConnectionStatus::Draft);
    fakeChannexMapping();

    expect(app(DisconnectChannel::class)(channelAdminUser(), $draft)->ok)->toBeTrue()
        ->and(channexPaths('POST'))->toBe([])
        ->and(channexPaths('DELETE'))->toBe(['/api/v1/channels/channel-1']);

    $gone = lifecycleConnection(ChannelConnectionStatus::Draft);
    resetChannexFake();
    Http::fake(['*' => Http::response(['errors' => ['code' => 'not_found']], 404)]);

    expect(app(DisconnectChannel::class)(channelAdminUser(), $gone)->ok)->toBeTrue()->and(ChannelConnection::query()->count())->toBe(0);
});

test('a connection Channex will not remove stays here', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Paused);
    Http::fake(['*' => Http::response(['errors' => ['code' => 'validation_error']], 422)]);

    $result = app(DisconnectChannel::class)(channelAdminUser(), $connection);

    expect($result->ok)->toBeFalse()->and($result->message)->toBe('Channex refused to remove Booking.com.')
        ->and(ChannelConnection::query()->count())->toBe(1)->and(ChannelOtaMapping::query()->count())->toBe(1);
    expect(Activity::query()->where('event', 'channel.disconnected')->count())->toBe(0);
});

test('future bookings are requested only for a running Booking.com or Expedia connection', function () {
    $active = lifecycleConnection(ChannelConnectionStatus::Active);
    fakeChannexMapping();

    expect(app(ImportFutureBookings::class)(channelAdminUser(), $active)->message)->toBe('Asked Booking.com for its future bookings. They arrive in a few minutes.')
        ->and(channexPaths('POST'))->toBe(['/api/v1/channels/channel-1/execute/load_future_reservations']);

    $paused = lifecycleConnection(ChannelConnectionStatus::Paused);
    $agoda = lifecycleConnection(ChannelConnectionStatus::Active, OtaChannel::factory()->create(['name' => 'Agoda', 'channex_code' => 'Agoda']));

    expect(app(ImportFutureBookings::class)(channelAdminUser(), $paused)->message)->toBe('Start Booking.com first, then import its bookings.')
        ->and(app(ImportFutureBookings::class)(channelAdminUser(), $agoda)->message)->toBe('Agoda cannot import bookings that already exist.');
    expect(channexPaths('POST'))->toHaveCount(1);
    expect(Activity::query()->where('event', 'channel.bookings_imported')->count())->toBe(1);
});

test('the connection routes act and send the admin back with a toast', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Mapped);
    $code = $connection->channelProperty->property->code;
    fakeChannexMapping();
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create());

    $this->post(route('admin.properties.channels.connections.activate', [$code, $connection]))->assertRedirect()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Booking.com is active. Channex is sending it availability and rates.']);
    $this->put(route('admin.properties.channels.connections.paused', [$code, $connection]), ['paused' => true])->assertRedirect();
    expect($connection->refresh()->status)->toBe(ChannelConnectionStatus::Paused);

    $this->put(route('admin.properties.channels.connections.paused', [$code, $connection]), ['paused' => false])->assertRedirect();
    expect($connection->refresh()->status)->toBe(ChannelConnectionStatus::Active);

    $this->post(route('admin.properties.channels.connections.import', [$code, $connection]))->assertRedirect();
    $this->put(route('admin.properties.channels.connections.paused', [$code, $connection]), [])->assertSessionHasErrors('paused');
    $this->delete(route('admin.properties.channels.connections.destroy', [$code, $connection]))->assertRedirect()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Booking.com disconnected.']);

    expect(ChannelConnection::query()->count())->toBe(0);
});

test('the connection routes are closed to another property and need two-factor', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Mapped);
    $other = channelAriSetup(externalId: 'prop-9')['property'];
    Feature::for($other)->activate(ChannelManager::class);
    fakeChannexMapping();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.properties.channels.connections.activate', [$other->code, $connection]))->assertNotFound();
    $this->delete(route('admin.properties.channels.connections.destroy', [$other->code, $connection]))->assertNotFound();

    config(['estatell.support_require_two_factor' => true]);
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.properties.channels.connections.activate', [$connection->channelProperty->property->code, $connection]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect($connection->refresh()->status)->toBe(ChannelConnectionStatus::Mapped)->and(channexPaths('POST'))->toBe([]);
});

test('the OTAs card shows what each connection can do', function () {
    $connection = lifecycleConnection(ChannelConnectionStatus::Mapped);
    $code = $connection->channelProperty->property->code;
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('admin.properties.channels', $code))->assertInertia(fn (Assert $page) => $page
        ->where('channel.otas.0.canActivate', true)->where('channel.otas.0.canPause', false)->where('channel.otas.0.canResume', false)->where('channel.otas.0.canImport', false));

    $connection->update(['status' => ChannelConnectionStatus::Active]);
    $this->get(route('admin.properties.channels', $code))->assertInertia(fn (Assert $page) => $page
        ->where('channel.otas.0.canActivate', false)->where('channel.otas.0.canPause', true)->where('channel.otas.0.canImport', true));

    $connection->update(['status' => ChannelConnectionStatus::Paused]);
    $this->get(route('admin.properties.channels', $code))->assertInertia(fn (Assert $page) => $page
        ->where('channel.otas.0.canResume', true)->where('channel.otas.0.canPause', false)->where('channel.otas.0.canImport', false));
});
