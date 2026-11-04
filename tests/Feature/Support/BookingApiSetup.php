<?php

require_once __DIR__.'/BookingSetup.php';

use App\Enums\CompanyAccess;
use App\Enums\SystemTransactionCode;
use App\Features\BookingEngine;
use App\Models\BookingClient;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\Rate;
use App\Models\Tax;
use App\Models\TransactionCode;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

const BOOKING_API_TOKEN = 'est_live_bookingclienttokenaaaaaaaaaaaaaaaaaaaa';

/**
 * A property the microsite client reaches: the flag on, a published site in Microsite mode, Malaysian, and the booking
 * setup (rooms at RM 100, a dorm at RM 40, 2026-10-20 to 2026-10-27) with both plans open to everyone and bookable online.
 *
 * @return array<string, mixed> The `bookingSetup()` keys plus `site` and `roomCode`.
 */
function bookingApiProperty(string $slug = 'sand-bay'): array
{
    $setup = bookingSetup();
    /** @var Property $property */
    $property = $setup['property'];
    $property->forceFill(['status' => 'live', 'slug' => $slug, 'country_code' => 'MY', 'city' => 'Semporna'])->save();
    Feature::for($property)->activate(BookingEngine::class);

    foreach (['plan', 'bedPlan'] as $key) {
        $setup[$key]->forceFill(['company_access' => CompanyAccess::Everyone, 'bookable_online' => true])->save();
    }

    $roomCode = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();

    return [
        ...$setup,
        'roomCode' => $roomCode,
        'site' => PropertyBookingSite::factory()->for($property)->published()->create(),
    ];
}

/**
 * The microsite client with the known token. Pass abilities to give the token others.
 *
 * @param  list<string>|null  $abilities
 */
function bookingMicrositeClient(?array $abilities = null): BookingClient
{
    return BookingClient::factory()->withToken(BOOKING_API_TOKEN, $abilities)->create();
}

/**
 * SST 8% on the room code, and tourism tax RM 10 a night for foreign guests, flagged Collect at check-in.
 *
 * @return array{sst: Tax, tourism: Tax}
 */
function bookingApiTaxes(Property $property, TransactionCode $code): array
{
    $sst = Tax::factory()->for($property)->create(['name' => 'SST 8%', 'rate' => '8.00']);
    $tourism = Tax::factory()->for($property)->create(['name' => 'Tourism tax', 'rate_type' => 'flat', 'rate' => '10.00', 'foreign_guests_only' => true, 'collect_at_check_in' => true]);
    $sst->applicableTransactionCodes()->attach($code);
    $tourism->applicableTransactionCodes()->attach($code);

    return ['sst' => $sst, 'tourism' => $tourism];
}

function bookingApiGet(string $path, ?string $token = BOOKING_API_TOKEN): TestResponse
{
    return test()->getJson('/api/booking/v1/'.$path, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

/**
 * @param  array<string, mixed>  $body
 */
function bookingApiPost(string $path, array $body, ?string $token = BOOKING_API_TOKEN): TestResponse
{
    return test()->postJson('/api/booking/v1/'.$path, $body, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

/**
 * Two nights, 2026-10-20 to 2026-10-22, 2 adults.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function bookingApiStay(array $body = []): array
{
    return ['arrival' => '2026-10-20', 'departure' => '2026-10-22', 'adults' => 2, ...$body];
}

/**
 * Set the price of a plan's room type on every date of the setup.
 */
function bookingApiSetRate(int $planId, int $roomTypeId, string $amount): void
{
    Rate::query()->where('rate_plan_id', $planId)->where('room_type_id', $roomTypeId)->update(['amount' => $amount]);
}
