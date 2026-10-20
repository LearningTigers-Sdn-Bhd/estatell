<?php

use App\Actions\Channels\SyncChannelStructure;
use App\Enums\ChannelPropertyStatus;
use App\Enums\PackageUnit;
use App\Enums\Weekday;
use App\Jobs\FlushChannelAriJob;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\PackagePrice;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ChannelStructure.php';

function syncStructure($property)
{
    return app(SyncChannelStructure::class)(User::factory()->admin()->create(), $property);
}

test('the first sync creates the property, room types and rate plans and saves their ids', function () {
    $property = channelProperty();
    $deluxe = channelRoomType($property, 'Deluxe King', 3);
    $family = channelRoomType($property, 'Family Suite', 2, ['max_adults' => 4]);
    channelRatePlan($property, [$deluxe, $family], 'Standard');
    fakeChannex();

    $result = syncStructure($property);

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toBe('2 room types and 2 rate plans sent to Channex.');

    $channelProperty = ChannelProperty::query()->where('property_id', $property->id)->firstOrFail();
    expect($channelProperty->external_property_id)->toBe('properties-1')
        ->and($channelProperty->status)->toBe(ChannelPropertyStatus::Synced)
        ->and($channelProperty->structure_synced_at)->not->toBeNull()
        ->and($channelProperty->last_error)->toBeNull()
        ->and(ChannelRoomTypeMapping::query()->count())->toBe(2)
        ->and(ChannelRatePlanMapping::query()->count())->toBe(2);

    $propertyBody = channexBodies('POST', '/properties')[0]['property'];
    expect($propertyBody)->toMatchArray([
        'title' => 'Seri Pagi Inn',
        'currency' => 'MYR',
        'timezone' => 'Asia/Kuala_Lumpur',
        'property_type' => 'hotel',
        'country' => 'MY',
        'state' => 'KUL',
        'address' => '1 Jalan Pagi',
        'zip_code' => '50450',
        'email' => 'hello@seripagi.my',
        'group_id' => 'group-1',
    ]);

    $roomBodies = collect(channexBodies('POST', '/room_types'))->pluck('room_type');
    expect($roomBodies->firstWhere('title', 'Deluxe King'))->toMatchArray(['property_id' => 'properties-1', 'count_of_rooms' => 3, 'occ_adults' => 2, 'occ_children' => 1, 'occ_infants' => 0, 'default_occupancy' => 2, 'room_kind' => 'room'])
        ->and($roomBodies->firstWhere('title', 'Family Suite'))->toMatchArray(['count_of_rooms' => 2, 'occ_adults' => 4]);

    $planBodies = collect(channexBodies('POST', '/rate_plans'))->pluck('rate_plan');
    expect($planBodies->pluck('title')->sort()->values()->all())->toBe(['Standard · Deluxe King', 'Standard · Family Suite'])
        ->and($planBodies->firstWhere('title', 'Standard · Deluxe King'))->toMatchArray([
            'property_id' => 'properties-1',
            'currency' => 'MYR',
            'sell_mode' => 'per_room',
            'rate_mode' => 'manual',
            'options' => [['occupancy' => 2, 'is_primary' => true, 'rate' => 0]],
        ]);

    expect(Activity::query()->where('event', 'channel.structure_synced')->count())->toBe(1);
});

test('syncing again updates the same records and never duplicates them', function () {
    $property = channelProperty();
    $deluxe = channelRoomType($property);
    channelRatePlan($property, [$deluxe], 'Standard');
    fakeChannex();

    syncStructure($property);
    $roomTypeId = ChannelRoomTypeMapping::query()->value('external_room_type_id');
    $deluxe->update(['name' => 'Deluxe King Plus']);
    resetChannexFake();
    fakeChannex();

    $result = syncStructure($property->refresh());

    expect($result->ok)->toBeTrue()
        ->and(ChannelProperty::query()->count())->toBe(1)
        ->and(ChannelRoomTypeMapping::query()->count())->toBe(1)
        ->and(ChannelRatePlanMapping::query()->count())->toBe(1)
        ->and(channexBodies('POST', '/'))->toBeEmpty();

    $roomUpdate = collect(channexRequests())->first(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), "/room_types/{$roomTypeId}"));
    expect($roomUpdate->data()['room_type']['title'])->toBe('Deluxe King Plus');

    $propertyUpdate = collect(channexRequests())->first(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/properties/properties-1'));
    expect($propertyUpdate->data()['property'])->not->toHaveKey('group_id');
});

test('a per guest plan is sent per person with one price option for each adult', function () {
    $property = channelProperty();
    $family = channelRoomType($property, 'Family Suite', 1, ['max_adults' => 3]);
    channelRatePlan($property, [$family], 'Per head', 'perGuest');
    fakeChannex();

    syncStructure($property);

    $plan = channexBodies('POST', '/rate_plans')[0]['rate_plan'];
    expect($plan['sell_mode'])->toBe('per_person')
        ->and($plan['options'])->toBe([
            ['occupancy' => 1, 'is_primary' => false, 'rate' => 0],
            ['occupancy' => 2, 'is_primary' => false, 'rate' => 0],
            ['occupancy' => 3, 'is_primary' => true, 'rate' => 0],
        ]);
});

test('a flat package is sent with min stay equal to max stay and closed arrival on other days', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    $package = channelRatePlan($property, [$roomType], '3-night package', 'package');
    $package->update(['fixed_nights' => 3, 'package_unit' => PackageUnit::PerRoom]);
    PackagePrice::factory()->create(['rate_plan_id' => $package->id, 'room_type_id' => $roomType->id, 'amount' => '600.00']);
    $package->arrivalWeekdays()->create(['weekday' => Weekday::Friday]);
    fakeChannex();

    syncStructure($property);

    $plan = channexBodies('POST', '/rate_plans')[0]['rate_plan'];
    expect($plan['sell_mode'])->toBe('per_room')
        ->and($plan['min_stay_arrival'])->toBe([3, 3, 3, 3, 3, 3, 3])
        ->and($plan['min_stay_through'])->toBe([3, 3, 3, 3, 3, 3, 3])
        ->and($plan['max_stay'])->toBe([3, 3, 3, 3, 3, 3, 3])
        ->and($plan['closed_to_arrival'])->toBe([true, true, true, true, false, true, true]);
});

test('skipped pairs are not sent', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType], 'Standard');
    channelRatePlan($property, [$roomType], 'Hourly', 'dayUse');
    fakeChannex();

    syncStructure($property);

    expect(collect(channexBodies('POST', '/rate_plans'))->pluck('rate_plan.title')->all())->toBe(['Standard · Deluxe King']);
});

test('a refusal part-way saves the ids so far and the next sync updates instead of duplicating', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType], 'Standard');
    Http::fake([
        '*/properties' => Http::response(['data' => ['id' => 'properties-1', 'attributes' => ['max_count_of_room_types' => 20, 'max_count_of_rate_plans' => 200]]], 201),
        '*/room_types' => Http::response(['data' => ['id' => 'rt-1']], 201),
        '*/rate_plans' => Http::response(['errors' => ['code' => 'validation_error', 'details' => ['title' => ['is taken']]]], 422),
    ]);

    $result = syncStructure($property);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Channex refused the request (422)')
        ->and($result->message)->toContain('is taken');

    $channelProperty = ChannelProperty::query()->firstOrFail();
    expect($channelProperty->status)->toBe(ChannelPropertyStatus::Error)
        ->and($channelProperty->last_error)->toBe($result->message)
        ->and($channelProperty->structure_synced_at)->toBeNull()
        ->and(ChannelRoomTypeMapping::query()->value('external_room_type_id'))->toBe('rt-1')
        ->and(ChannelRatePlanMapping::query()->count())->toBe(0);

    resetChannexFake();
    fakeChannex();
    $retry = syncStructure($property->refresh());

    expect($retry->ok)->toBeTrue()
        ->and(channexBodies('POST', '/properties'))->toBeEmpty()
        ->and(channexBodies('POST', '/room_types'))->toBeEmpty()
        ->and(channexBodies('POST', '/rate_plans'))->toHaveCount(1)
        ->and(ChannelProperty::query()->firstOrFail()->status)->toBe(ChannelPropertyStatus::Synced);
});

test('a rate limit or outage asks the admin to try again and keeps the error on the property', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType]);
    Http::fake([
        '*/properties' => Http::response(['data' => ['id' => 'prop-1', 'attributes' => []]], 201),
        '*/room_types' => Http::response([], 429),
    ]);

    $result = syncStructure($property);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe('Channex is busy or not reachable. Try again in a minute.')
        ->and(ChannelProperty::query()->firstOrFail()->status)->toBe(ChannelPropertyStatus::Error);
});

test('a failure before the property exists at Channex saves nothing', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType]);
    Http::fake(['*' => Http::response(['errors' => ['code' => 'unauthorized']], 401)]);

    $result = syncStructure($property);

    expect($result->ok)->toBeFalse()
        ->and(ChannelProperty::query()->count())->toBe(0);
});

test('the limits Channex reports for the property stop the sync before any room type is made', function () {
    $property = channelProperty();
    $one = channelRoomType($property, 'One');
    $two = channelRoomType($property, 'Two');
    channelRatePlan($property, [$one, $two]);
    fakeChannex(maxRoomTypes: 1);

    $result = syncStructure($property);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe('Channex allows 1 room types for this property, and 2 were to be sent.')
        ->and(channexBodies('POST', '/room_types'))->toBeEmpty()
        ->and(ChannelProperty::query()->count())->toBe(1);
});

test('nothing is sent when no pair can go to Channex', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType], 'Hourly', 'dayUse');
    Http::fake();

    $result = syncStructure($property);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBe('Nothing can be sent yet. Every room type and rate plan pair is skipped.');
    Http::assertNothingSent();
});

test('a server without a Channex key refuses and sends nothing', function () {
    $property = channelProperty();
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType]);
    config(['services.channex.api_key' => null]);
    Http::fake();

    $result = syncStructure($property);

    expect($result->ok)->toBeFalse()->and($result->message)->toBe('Channex is not set up on this server.');
    Http::assertNothingSent();
});

test('archived room types and rate plans are not sent', function () {
    $property = channelProperty();
    $active = channelRoomType($property, 'Active');
    $archived = channelRoomType($property, 'Old', 2, ['archived_at' => now()]);
    channelRatePlan($property, [$active, $archived], 'Standard');
    fakeChannex();

    syncStructure($property);

    expect(collect(channexBodies('POST', '/room_types'))->pluck('room_type.title')->all())->toBe(['Active']);
});

test('the first sync queues the 500-day full sync and a second sync does not', function () {
    Queue::fake();
    $property = channelProperty();
    channelRatePlan($property, [channelRoomType($property)], 'Standard');
    fakeChannex();

    syncStructure($property);
    syncStructure($property->refresh());

    Queue::assertPushed(FlushChannelAriJob::class, 1);
    Queue::assertPushed(FlushChannelAriJob::class, fn (FlushChannelAriJob $job): bool => $job->full);
});
