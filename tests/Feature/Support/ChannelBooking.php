<?php

require_once __DIR__.'/BookingSetup.php';
require_once __DIR__.'/ChannelStructure.php';

use App\Actions\Channels\StoreChannelRevision;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\ChannelBookingRevision;
use App\Models\ChannelProperty;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\User;
use Database\Seeders\OtaChannelSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The booking setup (a live property with an open business date and a cancellation policy, owner, Room room type, Basic plan priced for 2026-10-20 to 2026-10-27) already on Channex as
 * 'prop-1', room type 'room-1' and rate plan 'plan-1', with the OTA channels seeded.
 *
 * @return array{user: User, property: Property, roomType: RoomType, plan: RatePlan, channelProperty: ChannelProperty}
 */
function channelBookingSetup(): array
{
    config(['services.channex.base_url' => 'https://staging.channex.io/api/v1', 'services.channex.api_key' => 'secret-key']);
    (new OtaChannelSeeder)->run();

    $setup = bookingSetup();
    $setup['property']->forceFill(['status' => 'live'])->save();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id]);
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::CancellationRevenue)->create();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();
    $channelProperty = channelSyncedProperty($setup['property']);
    $channelProperty->roomTypeMappings()->create(['room_type_id' => $setup['roomType']->id, 'external_room_type_id' => 'room-1']);
    $channelProperty->ratePlanMappings()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'external_rate_plan_id' => 'plan-1']);

    return [...$setup, 'channelProperty' => $channelProperty];
}

/**
 * A Channex booking revision as the feed returns it (its attributes, with the revision `id`).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function channelRevisionPayload(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'property_id' => 'prop-1',
        'booking_id' => 'booking-1',
        'unique_id' => 'BDC-9996013801',
        'ota_reservation_code' => '9996013801',
        'ota_name' => 'Booking.com',
        'status' => 'new',
        'currency' => 'MYR',
        'amount' => '200.00',
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'notes' => 'Quiet room please',
        'customer' => ['name' => 'Aina', 'surname' => 'Rahman', 'mail' => 'aina@example.com', 'phone' => '+60 12-345 6788', 'country' => 'MY'],
        'rooms' => [channelRevisionRoom()],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function channelRevisionRoom(array $overrides = []): array
{
    return [
        'room_type_id' => 'room-1',
        'rate_plan_id' => 'plan-1',
        'checkin_date' => '2026-10-20',
        'checkout_date' => '2026-10-22',
        'amount' => '200.00',
        'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
        ...$overrides,
    ];
}

/** Store a revision for the setup's channel property, as `StoreChannelRevision` does. */
function storeChannelRevision(array $setup, array $attributes = []): ChannelBookingRevision
{
    return app(StoreChannelRevision::class)($setup['channelProperty'], channelRevisionPayload($attributes));
}

/** Channex takes every acknowledgement. */
function fakeChannexAck(int $status = 200): void
{
    Http::fake(['*/booking_revisions/*/ack' => Http::response(['meta' => ['message' => 'Success']], $status)]);
}

/** @return list<string> The URLs of the acknowledgements sent. */
function channexAcks(): array
{
    return array_values(array_map(fn ($request): string => $request->url(), array_filter(channexCalls(), fn ($request): bool => str_ends_with($request->url(), '/ack'))));
}
