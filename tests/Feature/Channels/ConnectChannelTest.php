<?php

use App\Actions\Channels\ConnectChannel;
use App\Enums\ChannelConnectionStatus;
use App\Features\ChannelManager;
use App\Models\ChannelConnection;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ChannelStructure.php';

function connectChannel(Property $property, OtaChannel $ota, array $settings = ['hotel_id' => '5868189'])
{
    return app(ConnectChannel::class)(User::factory()->admin()->create(), $property->channelProperty, $ota, $settings);
}

function connectReadyProperty(): Property
{
    $property = channelProperty();
    channelSyncedProperty($property);
    Feature::for($property)->activate(ChannelManager::class);

    return $property->refresh();
}

test('connecting an OTA tests the settings, creates a disabled channel at Channex and saves a draft', function () {
    $property = connectReadyProperty();
    $ota = bookingChannel();
    fakeChannexConnect();

    $result = connectChannel($property, $ota, ['hotel_id' => ' 5868189 ', 'send_email_notifications' => false, 'email' => 'x@y.my', 'machine_account' => 'hacked']);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('Booking.com connected to hotel 5868189.');

    $connection = ChannelConnection::query()->firstOrFail();
    expect($connection->status)->toBe(ChannelConnectionStatus::Draft)
        ->and($connection->external_channel_id)->toBe('channel-1')
        ->and($connection->ota_channel_id)->toBe($ota->id)
        ->and($connection->settings)->toEqual(['hotel_id' => '5868189', 'send_email_notifications' => false]);

    expect(channexBodies('POST', '/channels/test_connection')[0])->toBe(['channel' => 'BookingCom', 'settings' => ['hotel_id' => '5868189', 'send_email_notifications' => false]]);
    expect(channexBodies('POST', '/channels')[1]['channel'] ?? channexBodies('POST', '/channels')[0]['channel'])->toBe([
        'channel' => 'BookingCom',
        'group_id' => 'group-1',
        'title' => 'Booking.com - Seri Pagi Inn',
        'properties' => ['prop-1'],
        'settings' => ['hotel_id' => '5868189', 'send_email_notifications' => false],
    ]);

    $activity = Activity::query()->where('event', 'channel.connected')->sole();
    expect($activity->subject_id)->toBe($property->id)->and($activity->properties['connection_id'])->toBe($connection->id);
});

test('the property email is sent only while booking emails are on', function () {
    $property = connectReadyProperty();
    fakeChannexConnect();

    connectChannel($property, bookingChannel(), ['hotel_id' => '5868189', 'send_email_notifications' => true, 'email' => 'reservations@seripagi.my']);

    expect(ChannelConnection::query()->firstOrFail()->settings)->toEqual(['hotel_id' => '5868189', 'send_email_notifications' => true, 'email' => 'reservations@seripagi.my']);
});

test('a refused test connection saves nothing and creates nothing at Channex', function () {
    $property = connectReadyProperty();
    fakeChannexConnect(connectionOk: false);

    $result = connectChannel($property, bookingChannel());

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Booking.com refused the connection')
        ->and(ChannelConnection::query()->count())->toBe(0)
        ->and(channexBodies('POST', '/channels/test_connection'))->toHaveCount(1);

    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/channels') && $request->method() === 'POST');
    expect(Activity::query()->where('event', 'channel.connected')->count())->toBe(0);
});

test('a hotel ID Channex refuses saves nothing', function () {
    $property = connectReadyProperty();
    fakeChannexConnect(createStatus: 422);

    $result = connectChannel($property, bookingChannel());

    expect($result->ok)->toBeFalse()
        ->and($result->retryable)->toBeFalse()
        ->and($result->message)->toContain('may already be connected to another property')
        ->and(ChannelConnection::query()->count())->toBe(0);
});

test('a Channex outage is a failure that may pass by itself', function () {
    $property = connectReadyProperty();
    Http::fake(['*' => Http::response([], 503)]);

    $result = connectChannel($property, bookingChannel());

    expect($result->ok)->toBeFalse()->and($result->retryable)->toBeTrue()->and(ChannelConnection::query()->count())->toBe(0);
});

test('an OTA is connected once per property', function () {
    $property = connectReadyProperty();
    $ota = bookingChannel();
    ChannelConnection::factory()->create(['channel_property_id' => $property->channelProperty->id, 'ota_channel_id' => $ota->id]);
    fakeChannexConnect();

    $result = connectChannel($property, $ota);

    expect($result->ok)->toBeFalse()->and($result->message)->toBe('Booking.com is already connected for this property.');
    Http::assertNothingSent();
});

test('an OTA with no Channex adapter or no hotel ID is refused before the OTA is asked', function () {
    $property = connectReadyProperty();
    fakeChannexConnect();

    expect(connectChannel($property, OtaChannel::factory()->create(['name' => 'Traveloka']))->message)->toContain('has no Channex adapter')
        ->and(connectChannel($property, bookingChannel(), ['hotel_id' => '  '])->message)->toBe('Enter the Booking.com hotel ID first.')
        ->and(ChannelConnection::query()->count())->toBe(0);
    expect(channexBodies('POST', '/channels/test_connection'))->toBe([]);
});

test('the Connect page draws the adapter fields and starts the hotel ID from the OTA lists row', function () {
    $property = connectReadyProperty();
    $ota = bookingChannel();
    fakeChannexConnect();
    PropertyOtaCredential::factory()->for($property)->create(['ota_channel_id' => $ota->id, 'property_code' => '5868180', 'channel_name' => null]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->get(route('admin.properties.channels.connect', [$property->code, $ota]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/channels/connect')
            ->where('connect.otaName', 'Booking.com')
            ->where('connect.fields.0.name', 'hotel_id')
            ->where('connect.fields.0.required', true)
            ->where('connect.fields.0.placeholder', 'e.g. 5868189')
            ->where('connect.fields.1.name', 'send_email_notifications')
            ->where('connect.fields.2.name', 'email')
            ->where('connect.fields.2.hiddenWhenField', 'send_email_notifications')
            ->where('connect.fields.2.hiddenWhenValue', false)
            ->has('connect.fields', 3)
            ->where('connect.values.hotel_id', '5868180')
            ->where('connect.otaList.propertyCode', '5868180'));
});

test('the Connect page sends the admin back to the tab when the connection is not possible', function () {
    $property = connectReadyProperty();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $airbnb = OtaChannel::factory()->create(['code' => 'airbnb', 'name' => 'Airbnb', 'channex_code' => 'AirBNB']);
    $traveloka = OtaChannel::factory()->create(['name' => 'Traveloka']);
    fakeChannexConnect();

    $this->actingAs($admin)->get(route('admin.properties.channels.connect', [$property->code, $airbnb]))->assertRedirect(route('admin.properties.channels', $property->code));
    $this->get(route('admin.properties.channels.connect', [$property->code, $traveloka]))->assertRedirect(route('admin.properties.channels', $property->code));

    $unsynced = Property::factory()->create();
    Feature::for($unsynced)->activate(ChannelManager::class);
    $this->get(route('admin.properties.channels.connect', [$unsynced->code, bookingChannel(['code' => 'booking_two', 'name' => 'Booking.com 2'])]))->assertRedirect(route('admin.properties.channels', $unsynced->code));
});

test('the connect routes are 404 while the feature is off and closed to staff', function () {
    $property = channelProperty();
    channelSyncedProperty($property);
    $ota = bookingChannel();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get(route('admin.properties.channels.connect', [$property->code, $ota]))->assertNotFound();

    Feature::for($property)->activate(ChannelManager::class);
    $this->actingAs(User::factory()->create())->get(route('admin.properties.channels.connect', [$property->code, $ota]))->assertNotFound();
});

test('Test connection answers with JSON and the hotel ID is required', function () {
    $property = connectReadyProperty();
    $ota = bookingChannel();
    fakeChannexConnect();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->postJson(route('admin.properties.channels.test', [$property->code, $ota]), ['settings' => ['hotel_id' => '5868189']])
        ->assertOk()
        ->assertExactJson(['ok' => true, 'message' => 'Booking.com sees Channex as the provider for hotel 5868189.']);

    $this->postJson(route('admin.properties.channels.test', [$property->code, $ota]), ['settings' => ['hotel_id' => '']])->assertJsonValidationErrors('settings.hotel_id');
    expect(ChannelConnection::query()->count())->toBe(0);
});

test('Test connection needs two-factor like every admin write', function () {
    config(['estatell.support_require_two_factor' => true]);
    $property = connectReadyProperty();
    fakeChannexConnect();

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('admin.properties.channels.test', [$property->code, bookingChannel()]), ['settings' => ['hotel_id' => '5868189']])
        ->assertForbidden();
});

test('saving the connection goes on to the map step', function () {
    $property = connectReadyProperty();
    $ota = bookingChannel();
    fakeChannexConnect();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.properties.channels.connections.store', $property->code), ['ota_channel_id' => $ota->id, 'settings' => ['hotel_id' => '5868189']])
        ->assertRedirect(route('admin.properties.channels.connections.map', [$property->code, ChannelConnection::query()->sole()]));

    expect(ChannelConnection::query()->sole()->status)->toBe(ChannelConnectionStatus::Draft);
});

test('the OTAs card lists connectable channels and the reason for the others', function () {
    $property = connectReadyProperty();
    $booking = bookingChannel();
    OtaChannel::factory()->create(['code' => 'airbnb', 'name' => 'Airbnb', 'channex_code' => 'AirBNB']);
    $traveloka = OtaChannel::factory()->create(['name' => 'Traveloka']);
    OtaChannel::factory()->create(['name' => 'Unused']);
    PropertyOtaCredential::factory()->for($property)->create(['ota_channel_id' => $traveloka->id, 'property_code' => '777', 'channel_name' => null]);
    ChannelConnection::factory()->create(['channel_property_id' => $property->channelProperty->id, 'ota_channel_id' => $booking->id, 'settings' => ['hotel_id' => '5868189']]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->get(route('admin.properties.channels', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('channel.otas', 3)
            ->where('channel.otas.0.name', 'Booking.com')
            ->where('channel.otas.0.hotelId', '5868189')
            ->where('channel.otas.0.status.label', 'Draft')
            ->where('channel.otas.0.canConnect', false)
            ->where('channel.otas.1.name', 'Airbnb')
            ->where('channel.otas.1.canConnect', false)
            ->where('channel.otas.1.note', 'Connect in Channex by hand (coming later)')
            ->where('channel.otas.2.name', 'Traveloka')
            ->where('channel.otas.2.hotelId', '777')
            ->where('channel.otas.2.note', 'No Channex adapter. Connect in Channex by hand.'));
});

test('an adapter that names the hotel hotel_code (Trip.com) connects with it', function () {
    $property = connectReadyProperty();
    $ota = OtaChannel::factory()->create(['code' => 'trip_com', 'name' => 'Trip.com', 'channex_code' => 'CTrip']);
    PropertyOtaCredential::factory()->for($property)->create(['ota_channel_id' => $ota->id, 'property_code' => '28707116', 'channel_name' => null]);
    Http::fake([
        '*/channels/adapter*' => Http::response(['data' => ['code' => 'CTrip', 'params' => [
            'hotel_code' => ['position' => 0, 'type' => 'string', 'title' => 'Hotel Code'],
            'send_email_notifications' => ['default' => false, 'position' => 1, 'type' => 'boolean', 'title' => 'Send booking emails'],
        ]]], 200),
        '*/channels/test_connection' => Http::response(['data' => ['success' => true, 'errors' => null]], 200),
        '*/channels' => Http::response(['data' => ['id' => 'channel-1', 'type' => 'channel', 'attributes' => ['id' => 'channel-1']]], 201),
    ]);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('admin.properties.channels.connect', [$property->code, $ota]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connect.fields.0.name', 'hotel_code')
            ->where('connect.fields.0.required', true)
            ->where('connect.fields.0.label', 'Hotel ID')
            ->where('connect.values.hotel_code', '28707116'));

    $this->postJson(route('admin.properties.channels.test', [$property->code, $ota]), ['settings' => ['hotel_code' => '28707116']])
        ->assertOk()
        ->assertExactJson(['ok' => true, 'message' => 'Trip.com sees Channex as the provider for hotel 28707116.']);
    $this->postJson(route('admin.properties.channels.test', [$property->code, $ota]), ['settings' => ['hotel_code' => '']])
        ->assertJsonValidationErrors('settings.hotel_code');

    expect(connectChannel($property, $ota, ['hotel_code' => '28707116'])->message)->toBe('Trip.com connected to hotel 28707116.')
        ->and(ChannelConnection::query()->sole()->settings['hotel_code'])->toBe('28707116');
});
