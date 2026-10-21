<?php

use App\Actions\Channels\SaveChannelMapping;
use App\Enums\ChannelConnectionStatus;
use App\Features\ChannelManager;
use App\Models\ChannelConnection;
use App\Models\ChannelOtaMapping;
use App\Models\ChannelRatePlanMapping;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ChannelStructure.php';

/**
 * A Booking.com draft connection on a synced property whose one rate plan is sold per person (room type max 3 adults),
 * so it has three occupancy lines.
 *
 * @return array{property: Property, connection: ChannelConnection, mapping: ChannelRatePlanMapping}
 */
function mapReadySetup(string $sellMode = 'per_person'): array
{
    ['property' => $property, 'channelProperty' => $channelProperty, 'roomType' => $roomType] = channelAriSetup(roomTypeAttributes: ['max_adults' => 3]);
    $mapping = $channelProperty->ratePlanMappings()->firstOrFail();
    $mapping->update(['sell_mode' => $sellMode]);
    $connection = ChannelConnection::factory()->create([
        'channel_property_id' => $channelProperty->id,
        'ota_channel_id' => bookingChannel()->id,
        'external_channel_id' => 'channel-1',
        'settings' => ['hotel_id' => '5868189'],
    ]);
    Feature::for($property)->activate(ChannelManager::class);

    return ['property' => $property, 'connection' => $connection, 'mapping' => $mapping->refresh()];
}

function saveMapping(ChannelConnection $connection, array $lines)
{
    return app(SaveChannelMapping::class)(User::factory()->admin()->create(), $connection->refresh(), $lines);
}

function line(ChannelRatePlanMapping $mapping, int $occupancy, ?string $ota, ?bool $primary = null, ?string $type = null): array
{
    return ['rate_plan_mapping_id' => $mapping->id, 'occupancy' => $occupancy, 'ota' => $ota, ...($primary === null ? [] : ['is_primary' => $primary]), 'rate_type' => $type];
}

test('saving a mapping sends the OTA codes as the OTA gave them and keeps the lines', function () {
    ['connection' => $connection, 'mapping' => $mapping, 'property' => $property] = mapReadySetup();
    fakeChannexMapping();

    $result = saveMapping($connection, [line($mapping, 3, '586818903|16385046', true), line($mapping, 2, '586818903|16385046', false), line($mapping, 1, null)]);

    expect($result->ok)->toBeTrue()->and($result->message)->toBe('2 lines mapped for Booking.com.');

    $put = channexBodies('PUT', '/channels/channel-1')[0]['channel'];
    expect($put['channel'])->toBe('BookingCom')
        ->and($put['properties'])->toBe(['prop-1'])
        ->and($put['settings'])->toBe(['hotel_id' => '5868189'])
        ->and($put['rate_plans'])->toBe([
            ['rate_plan_id' => 'plan-1', 'settings' => ['room_type_code' => 586818903, 'rate_plan_code' => 16385046, 'occupancy' => 3, 'pricing_type' => 'OBP', 'primary_occ' => true, 'readonly' => false]],
            ['rate_plan_id' => 'plan-1', 'settings' => ['room_type_code' => 586818903, 'rate_plan_code' => 16385046, 'occupancy' => 2, 'pricing_type' => 'OBP', 'primary_occ' => false, 'readonly' => false]],
        ]);

    $connection->refresh();
    expect($connection->status)->toBe(ChannelConnectionStatus::Mapped)
        ->and($connection->otaMappings()->count())->toBe(2)
        ->and($connection->otaMappings()->where('occupancy', 3)->sole()->only(['ota_room_code', 'ota_rate_code', 'is_primary']))->toBe(['ota_room_code' => '586818903', 'ota_rate_code' => '16385046', 'is_primary' => true]);

    expect(Activity::query()->where('event', 'channel.mapped')->sole()->subject_id)->toBe($property->id);
});

test('each OTA rate gets exactly one primary line: the flagged one, else the highest occupancy', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    fakeChannexMapping();

    saveMapping($connection, [line($mapping, 3, '586818903|16385046', false), line($mapping, 2, '586818903|16385046', true), line($mapping, 1, '586818903|16385046', true)]);

    expect($connection->otaMappings()->where('is_primary', true)->pluck('occupancy')->all())->toBe([2]);

    resetChannexFake();
    fakeChannexMapping();
    saveMapping($connection, [line($mapping, 3, '586818903|16385046'), line($mapping, 2, '586818903|16385046'), line($mapping, 1, '586818903|16385046')]);

    expect($connection->otaMappings()->where('is_primary', true)->pluck('occupancy')->all())->toBe([3]);
});

test('saving replaces the old mapping, and an empty mapping puts the connection back to draft', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    ChannelOtaMapping::factory()->create(['channel_connection_id' => $connection->id, 'channel_rate_plan_mapping_id' => $mapping->id, 'occupancy' => 1, 'ota_room_code' => '1', 'ota_rate_code' => '2']);
    $connection->update(['status' => ChannelConnectionStatus::Mapped]);
    fakeChannexMapping();

    saveMapping($connection, [line($mapping, 2, '586818903|16385046')]);
    expect($connection->otaMappings()->pluck('occupancy')->all())->toBe([2]);

    saveMapping($connection, [line($mapping, 2, null)]);

    expect($connection->otaMappings()->count())->toBe(0)
        ->and($connection->refresh()->status)->toBe(ChannelConnectionStatus::Draft)
        ->and(channexBodies('PUT', '/channels/channel-1')[1]['channel']['rate_plans'])->toBe([]);
});

test('an active connection stays active when its mapping changes', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    $connection->update(['status' => ChannelConnectionStatus::Active]);
    fakeChannexMapping();

    saveMapping($connection, [line($mapping, 3, '586818903|16385046')]);

    expect($connection->refresh()->status)->toBe(ChannelConnectionStatus::Active)->and($connection->otaMappings()->count())->toBe(1);
});

test('a room or rate the OTA does not have is refused and nothing is sent', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    fakeChannexMapping();

    $result = saveMapping($connection, [line($mapping, 3, '586818903|99999')]);

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('no longer has a room or rate')
        ->and($connection->otaMappings()->count())->toBe(0)
        ->and(channexBodies('PUT', '/channels/channel-1'))->toBe([]);
});

test('a line for another property or an occupancy the plan does not have is refused', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    $foreign = channelAriSetup(externalId: 'prop-2')['channelProperty']->ratePlanMappings()->firstOrFail();
    fakeChannexMapping();

    expect(saveMapping($connection, [line($foreign, 2, '586818903|16385046')])->ok)->toBeFalse()
        ->and(saveMapping($connection, [line($mapping, 4, '586818903|16385046')])->ok)->toBeFalse()
        ->and(saveMapping($connection, [line($mapping, 2, '586818903|16385046'), line($mapping, 2, '586818903|16385046')])->message)->toContain('mapped twice');
    expect(channexBodies('PUT', '/channels/channel-1'))->toBe([]);
});

test('a mapping Channex refuses saves nothing and keeps the old one', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    ChannelOtaMapping::factory()->create(['channel_connection_id' => $connection->id, 'channel_rate_plan_mapping_id' => $mapping->id, 'occupancy' => 1, 'ota_room_code' => '1', 'ota_rate_code' => '2']);
    fakeChannexMapping(updateStatus: 422);

    $result = saveMapping($connection, [line($mapping, 3, '586818903|16385046')]);

    expect($result->ok)->toBeFalse()->and($result->retryable)->toBeFalse()
        ->and($connection->otaMappings()->pluck('occupancy')->all())->toBe([1])
        ->and(Activity::query()->where('event', 'channel.mapped')->count())->toBe(0);
});

test('an OTA that does not answer is a failure that may pass by itself or a refusal', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    fakeChannexMapping(detailsStatus: 422);

    expect(saveMapping($connection, [line($mapping, 3, '586818903|16385046')])->message)->toContain('did not give its rooms and rates');

    resetChannexFake();
    Http::fake(['*' => Http::response([], 503)]);

    $result = saveMapping($connection, [line($mapping, 3, '586818903|16385046')]);
    expect($result->retryable)->toBeTrue()->and($connection->otaMappings()->count())->toBe(0);
});

test('Hostelworld sends a type and no primary flag', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    $adapter = ['code' => 'Hostelworld', 'params' => [], 'rate_params' => [
        'rate_plan_code' => ['type' => 'string'], 'room_type_code' => ['type' => 'string'], 'occupancy' => ['type' => 'integer'], 'type' => ['type' => 'string'],
    ]];
    fakeChannexMapping(adapter: $adapter);

    saveMapping($connection, [line($mapping, 3, '586818903|16385046', true, 'private')]);

    expect(channexBodies('PUT', '/channels/channel-1')[0]['channel']['rate_plans'][0]['settings'])->toBe(['room_type_code' => 586818903, 'rate_plan_code' => 16385046, 'occupancy' => 3, 'type' => 'private'])
        ->and($connection->otaMappings()->sole()->only(['is_primary', 'rate_type']))->toBe(['is_primary' => null, 'rate_type' => 'private']);
});

test('one plan occupancy can take several OTA lines', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup();
    fakeChannexMapping();

    $result = saveMapping($connection, [line($mapping, 2, '586818903|16385046', true), line($mapping, 2, '586818903|16385047', true)]);

    expect($result->ok)->toBeTrue()
        ->and($connection->otaMappings()->count())->toBe(2)
        ->and(channexBodies('PUT', '/channels/channel-1')[0]['channel']['rate_plans'])->toHaveCount(2);
});

test('the map page lists each OTA line from the top guest count, suggests matches by name and marks one primary', function () {
    ['connection' => $connection, 'property' => $property] = mapReadySetup();
    fakeChannexMapping();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('admin.properties.channels.connections.map', [$property->code, $connection]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/channels/map')
            ->where('map.otaName', 'Booking.com')
            ->where('map.usesPrimary', true)
            ->where('map.usesType', false)
            ->where('map.pricingType', 'OBP')
            ->has('map.rows', 6)
            ->where('map.rows.0.roomTitle', 'Deluxe King')
            ->where('map.rows.0.rateTitle', 'Standard')
            ->where('map.rows.0.occupancy', 3)
            ->where('map.rows.0.estatell', fn ($value) => $value !== null)
            ->where('map.rows.0.isPrimary', true)
            ->where('map.rows.1.occupancy', 2)
            ->where('map.rows.1.isPrimary', false)
            ->where('map.rows.2.occupancy', 1)
            ->where('map.rows.3.rateTitle', 'Non-refundable')
            ->where('map.rows.3.estatell', null)
            ->has('map.options', 1)
            ->where('map.options.0.roomType', 'Deluxe King')
            ->where('map.options.0.ratePlan', 'Standard')
            ->where('map.options.0.perRoom', false)
            ->where('map.options.0.occupancies', [1, 2, 3]));
});

test('a plan sold per room takes any guest count, and a saved mapping beats suggestions', function () {
    ['connection' => $connection, 'property' => $property, 'mapping' => $mapping] = mapReadySetup('per_room');
    fakeChannexMapping();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('admin.properties.channels.connections.map', [$property->code, $connection]))
        ->assertInertia(fn (Assert $page) => $page->where('map.options.0.perRoom', true)->where('map.rows.0.estatell', (string) $mapping->id)->where('map.rows.2.estatell', (string) $mapping->id)->where('map.rows.3.estatell', null));

    ChannelOtaMapping::factory()->create(['channel_connection_id' => $connection->id, 'channel_rate_plan_mapping_id' => $mapping->id, 'occupancy' => 1, 'ota_room_code' => '586818903', 'ota_rate_code' => '16385047', 'is_primary' => true]);

    $this->get(route('admin.properties.channels.connections.map', [$property->code, $connection]))
        ->assertInertia(fn (Assert $page) => $page->where('map.rows.0.estatell', null)->where('map.rows.5.estatell', (string) $mapping->id)->where('map.rows.5.isPrimary', true));
});

test('a line of a per-room plan keeps the OTA guest count it was saved with', function () {
    ['connection' => $connection, 'mapping' => $mapping] = mapReadySetup('per_room');
    fakeChannexMapping();

    expect(saveMapping($connection, [line($mapping, 1, '586818903|16385046', true), line($mapping, 2, '586818903|16385046', true)])->ok)->toBeTrue()
        ->and(array_column(array_column(channexBodies('PUT', '/channels/channel-1')[0]['channel']['rate_plans'], 'settings'), 'occupancy'))->toBe([1, 2])
        ->and($connection->otaMappings()->where('is_primary', true)->pluck('occupancy')->all())->toBe([2]);
});

test('the map page is closed to other properties and goes back to the tab when the OTA does not answer', function () {
    ['connection' => $connection, 'property' => $property] = mapReadySetup();
    $other = channelAriSetup(externalId: 'prop-9')['property'];
    Feature::for($other)->activate(ChannelManager::class);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    fakeChannexMapping();

    $this->actingAs($admin)->get(route('admin.properties.channels.connections.map', [$other->code, $connection]))->assertNotFound();

    resetChannexFake();
    fakeChannexMapping(detailsStatus: 422);
    $this->get(route('admin.properties.channels.connections.map', [$property->code, $connection]))->assertRedirect(route('admin.properties.channels', $property->code));
});

test('saving from the page redirects to the tab and refuses lines of another property', function () {
    ['connection' => $connection, 'property' => $property, 'mapping' => $mapping] = mapReadySetup();
    fakeChannexMapping();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(route('admin.properties.channels.connections.update', [$property->code, $connection]), ['lines' => [['rate_plan_mapping_id' => $mapping->id, 'occupancy' => 3, 'ota' => '586818903|16385046', 'is_primary' => true]]])
        ->assertRedirect(route('admin.properties.channels', $property->code));

    expect($connection->otaMappings()->count())->toBe(1);

    $this->put(route('admin.properties.channels.connections.update', [$property->code, $connection]), ['lines' => 'nope'])->assertSessionHasErrors('lines');
});
