<?php

use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelProperty;
use App\Models\OtaChannel;
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
 * Answer every Channex call like the real API: a POST makes `{resource}-{n}`, a PUT or DELETE keeps the id in the URL,
 * and `GET /rate_plans` lists the rate plans created or updated so far (with their title and sell mode). Pass
 * `$ratePlans` to start with plans Channex already holds: `['id' => ['title' => '…', 'sell_mode' => 'per_room']]`.
 *
 * @param  array<string, array{title: string, sell_mode: string}>  $ratePlans
 */
function fakeChannex(int $maxRoomTypes = 20, int $maxRatePlans = 200, array $ratePlans = []): void
{
    $counter = 0;

    Http::fake(function (Request $request) use (&$counter, &$ratePlans, $maxRoomTypes, $maxRatePlans) {
        $segments = explode('/', trim(Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1'), '/'));
        $isRatePlan = $segments[0] === 'rate_plans';

        if ($segments[0] === 'channels' && $request->method() === 'GET') {
            return Http::response(['data' => []], 200);
        }

        if ($isRatePlan && $request->method() === 'GET' && isset($segments[1])) {
            return Http::response(['data' => ['id' => $segments[1], 'type' => 'rate_plan', 'relationships' => ['property' => ['data' => ['id' => 'properties-1', 'type' => 'property']]]]], 200);
        }

        if ($isRatePlan && $request->method() === 'GET') {
            return Http::response(['data' => array_values(array_map(
                fn (string $id, array $plan): array => ['id' => $id, 'type' => 'rate_plan', 'attributes' => $plan],
                array_keys($ratePlans),
                $ratePlans,
            ))], 200);
        }

        $id = $request->method() === 'POST' ? $segments[0].'-'.(++$counter) : ($segments[1] ?? null);

        if ($isRatePlan && $request->method() === 'DELETE') {
            unset($ratePlans[$id]);
        }

        if ($isRatePlan && in_array($request->method(), ['POST', 'PUT'], true)) {
            $plan = $request->data()['rate_plan'] ?? [];
            $ratePlans[$id] = ['title' => $plan['title'] ?? '', 'sell_mode' => $plan['sell_mode'] ?? 'per_room'];
        }

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

/** The Booking.com adapter descriptor as Channex returns it (trimmed to the params the form needs). @return array<string, mixed> */
function channexBookingAdapter(): array
{
    return [
        'code' => 'BookingCom',
        'title' => 'Booking.com',
        'actions' => ['load_future_reservations'],
        'params' => [
            'hotel_id' => ['position' => 0, 'type' => 'string', 'title' => 'Hotel ID'],
            'machine_account' => ['position' => 1, 'type' => 'hidden', 'title' => 'Machine Account'],
            'send_email_notifications' => ['default' => false, 'position' => 2, 'type' => 'boolean', 'title' => 'Send Property Notification'],
            'email' => ['position' => 3, 'type' => 'string', 'title' => 'Property Email', 'rules' => [['apply' => 'hidden', 'when' => false, 'influence_field' => 'send_email_notifications', 'with_value' => '']]],
            'allow_payout_update' => ['default' => false, 'position' => 5, 'type' => 'hidden', 'title' => 'Allow Payout Updates'],
        ],
        'rate_params' => [
            'rate_plan_code' => ['position' => 0, 'type' => 'string', 'title' => 'Rate'],
            'room_type_code' => ['position' => 1, 'type' => 'string', 'title' => 'Room'],
            'occupancy' => ['position' => 2, 'type' => 'integer', 'title' => 'Occupancy'],
            'pricing_type' => ['position' => 3, 'type' => 'select', 'options' => ['Standard', 'OBP'], 'title' => 'Pricing Type'],
            'primary_occ' => ['position' => 4, 'type' => 'boolean', 'title' => 'Primary Occupancy'],
            'readonly' => ['position' => 5, 'type' => 'boolean', 'title' => 'Read Only'],
        ],
    ];
}

/**
 * Answer the Channel API calls of the connect flow: the Booking.com adapter, test_connection, and POST /channels.
 */
function fakeChannexConnect(bool $connectionOk = true, int $createStatus = 201): void
{
    Http::fake([
        '*/channels/adapter*' => Http::response(['data' => channexBookingAdapter()], 200),
        '*/channels/test_connection' => Http::response(['data' => ['success' => $connectionOk, 'errors' => null]], 200),
        '*/channels' => Http::response($createStatus === 201
            ? ['data' => ['id' => 'channel-1', 'type' => 'channel', 'attributes' => ['id' => 'channel-1', 'currency' => 'GBP']]]
            : ['errors' => ['code' => 'validation_error', 'details' => ['settings' => ['hotel_id already used']]]], $createStatus),
    ]);
}

/** The Booking.com OTA channel as the seeder makes it. */
function bookingChannel(array $attributes = []): OtaChannel
{
    return OtaChannel::factory()->create(['code' => 'booking_com', 'name' => 'Booking.com', 'channex_code' => 'BookingCom', ...$attributes]);
}

/**
 * Answer the Channel API calls of the map step: the adapter, `mapping_details` (Booking.com's shape: one room with one
 * rate that takes 1 to 3 guests), and `PUT /channels/{id}`.
 *
 * @param  array<string, mixed>|null  $details
 * @param  array<string, mixed>|null  $adapter
 */
function fakeChannexMapping(?array $details = null, int $updateStatus = 200, ?array $adapter = null, int $detailsStatus = 200): void
{
    Http::fake([
        '*/channels/adapter*' => Http::response(['data' => $adapter ?? channexBookingAdapter()], 200),
        '*/channels/mapping_details' => Http::response($detailsStatus === 200 ? ['data' => $details ?? channexBookingDetails()] : ['errors' => ['code' => 'bad_request']], $detailsStatus),
        '*/channels/*' => Http::response($updateStatus === 200
            ? ['data' => ['id' => 'channel-1', 'type' => 'channel', 'attributes' => ['id' => 'channel-1']]]
            : ['errors' => ['code' => 'validation_error', 'details' => ['rate_plans' => ['bad occupancy']]]], $updateStatus),
    ]);
}

/** The OTA rooms and rates `mapping_details` answers with. @return array<string, mixed> */
function channexBookingDetails(): array
{
    return [
        'pricing_type' => 'OBP',
        'rooms' => [
            ['id' => 586818903, 'title' => 'Deluxe King', 'max_children' => 0, 'rates' => [
                ['id' => 16385046, 'title' => 'Standard', 'max_persons' => 3, 'occupancies' => [1, 2, 3], 'pricing' => 'OBP', 'readonly' => false],
                ['id' => 16385047, 'title' => 'Non-refundable', 'max_persons' => 3, 'occupancies' => [1, 2, 3], 'pricing' => 'OBP', 'readonly' => false],
            ]],
        ],
    ];
}
