<?php

use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelProperty;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** A property with settings, ready to be sent to Channex. */
function channelProperty(): Property
{
    config([
        'services.channex.base_url' => 'https://staging.channex.io/api/v1',
        'services.channex.api_key' => 'secret-key',
        'services.channex.group_id' => 'group-1',
    ]);

    $property = Property::factory()->create([
        'name' => 'Seri Pagi Inn',
        'address_line1' => '1 Jalan Pagi',
        'state_code' => 'KUL',
        'postal_code' => '50450',
        'contact_email' => 'hello@seripagi.my',
    ]);
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR', 'time_zone' => 'Asia/Kuala_Lumpur']);

    return $property->refresh();
}

/** A room type with active rooms. */
function channelRoomType(Property $property, string $name = 'Deluxe King', int $rooms = 2, array $attributes = []): RoomType
{
    $roomType = RoomType::factory()->for($property)->create(['name' => $name, ...$attributes]);
    Room::factory()->count($rooms)->for($property)->create(['room_type_id' => $roomType->id]);

    return $roomType;
}

/** A rate plan linked to the room types. */
function channelRatePlan(Property $property, array $roomTypes, string $name = 'Standard', string $state = 'create', array $attributes = []): RatePlan
{
    $factory = RatePlan::factory()->for($property);
    $ratePlan = $state === 'create' ? $factory->create(['name' => $name, ...$attributes]) : $factory->{$state}()->create(['name' => $name, ...$attributes]);
    $ratePlan->roomTypes()->attach(array_map(fn (RoomType $roomType): int => $roomType->id, $roomTypes));

    return $ratePlan;
}

/** A property already on Channex, as if it had been synced. */
function channelSyncedProperty(Property $property, string $externalId = 'prop-1'): ChannelProperty
{
    return ChannelProperty::factory()->for($property)->create([
        'provider' => ChannelProvider::Channex,
        'external_property_id' => $externalId,
        'status' => ChannelPropertyStatus::Synced,
    ]);
}

/**
 * Answer every Channex call like the real API: a POST makes `{resource}-{n}`, a PUT or DELETE keeps the id in the URL.
 */
function fakeChannex(int $maxRoomTypes = 20, int $maxRatePlans = 200): void
{
    $counter = 0;

    Http::fake(function (Request $request) use (&$counter, $maxRoomTypes, $maxRatePlans) {
        $segments = explode('/', trim(Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1'), '/'));
        $id = $request->method() === 'POST' ? $segments[0].'-'.(++$counter) : ($segments[1] ?? null);

        return Http::response(['data' => ['id' => $id, 'attributes' => [
            'max_count_of_room_types' => $maxRoomTypes,
            'max_count_of_rate_plans' => $maxRatePlans,
        ]]], 200);
    });
}

/** The JSON bodies sent with a method to a path such as /rate_plans. @return list<array<string, mixed>> */
function channexBodies(string $method, string $path): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === $method && str_contains($pair[0]->url(), $path))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

/** Forget the calls recorded so far and the answers faked, so the next fake starts clean. */
function resetChannexFake(): void
{
    Http::swap(new Factory);
}

/** Every request sent so far. @return list<Request> */
function channexRequests(): array
{
    return Http::recorded()->map(fn (array $pair): Request => $pair[0])->values()->all();
}

/** The requests that went to Channex. Inertia SSR calls are not counted. @return list<Request> */
function channexCalls(): array
{
    return array_values(array_filter(channexRequests(), fn (Request $request): bool => str_contains($request->url(), 'channex.io')));
}

/**
 * A synced property with one room type and one rate plan, both already on Channex ('prop-1', 'room-1', 'plan-1').
 *
 * @return array{property: Property, channelProperty: ChannelProperty, roomType: RoomType, ratePlan: RatePlan}
 */
function channelAriSetup(int $rooms = 2, string $state = 'create', array $planAttributes = [], array $roomTypeAttributes = [], string $externalId = 'prop-1'): array
{
    $property = channelProperty();
    $roomType = channelRoomType($property, 'Deluxe King', $rooms, $roomTypeAttributes);
    $ratePlan = channelRatePlan($property, [$roomType], 'Standard', $state, $planAttributes)->refresh();
    $channelProperty = channelSyncedProperty($property, $externalId);
    $channelProperty->roomTypeMappings()->create(['room_type_id' => $roomType->id, 'external_room_type_id' => 'room-1']);
    $channelProperty->ratePlanMappings()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'external_rate_plan_id' => 'plan-1']);

    return compact('property', 'channelProperty', 'roomType', 'ratePlan');
}

/** A rate row for the plan and room type on a date. */
function channelRate(RatePlan $ratePlan, RoomType $roomType, string $date, ?string $amount, array $attributes = []): void
{
    Rate::factory()->create(['rate_plan_id' => $ratePlan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => $amount, ...$attributes]);
}
