<?php

require_once __DIR__.'/BookingSetup.php';
require_once __DIR__.'/PortalSetup.php';

use App\Enums\PolicyScope;
use App\Enums\PortalAccess;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * The booking setup (a Room room type with two rooms, a dorm of three beds, a plan for each priced for 2026-10-20 to
 * 2026-10-27, staff owner, Company / Travel agent source) made ready for the company portal: a Live property with an
 * open business date (the frozen day, 2026-10-14), both plans open to All companies, a cancellation policy, and a
 * company user with an Active link to a new company "Borneo Travel".
 *
 * @return array<string, mixed> The `bookingSetup()` keys plus `portalUser`, `company` and `link`.
 */
function portalBookingSetup(): array
{
    $setup = bookingSetup();
    /** @var Property $property */
    $property = $setup['property'];
    $property->forceFill(['status' => 'live'])->save();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::CancellationRevenue)->create();
    RoomRevenuePolicy::factory()->for($property)->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();

    foreach (['plan', 'bedPlan'] as $key) {
        $setup[$key]->forceFill(['portal_access' => PortalAccess::All])->save();
    }

    /** @var User $user */
    $user = portalUser();
    /** @var array{0: Company, 1: CompanyUser} $linked */
    $linked = portalLinkFor($user, $property);

    return [...$setup, 'portalUser' => $user, 'company' => $linked[0], 'link' => $linked[1]];
}

/**
 * A search or booking body for one Room line, 2026-10-20 to 2026-10-22, 2 adults.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function portalRoomLine(array $setup, array $line = []): array
{
    return ['room_type_id' => $setup['roomType']->id, 'adults' => 2, 'child_ages' => [], ...$line];
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function portalDormLine(array $setup, int $beds, array $line = []): array
{
    return ['room_type_id' => $setup['dorm']->id, 'adults' => 1, 'beds' => $beds, ...$line];
}

/**
 * A full booking body: the lines get the Basic plan (or the dorm plan for a dorm line) and a guest name unless they
 * already have them.
 *
 * @param  array<string, mixed>  $setup
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function portalBookingBody(array $setup, array $lines, array $overrides = []): array
{
    $lines = array_map(fn (array $line, int $index): array => [
        'rate_plan_id' => $line['room_type_id'] === $setup['dorm']->id ? $setup['bedPlan']->id : $setup['plan']->id,
        'guest_name' => 'Guest '.($index + 1),
        ...$line,
    ], $lines, array_keys($lines));

    return ['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22', 'lines' => $lines, 'expected_total' => '200.00', ...$overrides];
}

function portalAutoAddOn(Property $property, string $amount = '10.00'): ChargeItem
{
    return ChargeItem::factory()->for($property)->create(['name' => 'Cleaning fee', 'default_amount' => $amount, 'auto_add' => true]);
}

/**
 * Post a booking as the company user, from the book page.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $body
 */
function portalBook(array $setup, array $body): TestResponse
{
    return test()->actingAs($setup['portalUser'])->from(route('portal.book', $setup['property']))->post(route('portal.book.store', $setup['property']), $body);
}

/**
 * @return array{reservations: int, guests: int, units: int, activity: int}
 */
function portalCounts(): array
{
    return [
        'reservations' => Reservation::query()->count(),
        'guests' => Guest::query()->count(),
        'units' => ReservationUnit::query()->count(),
        'activity' => Activity::query()->count(),
    ];
}

/**
 * Book one Room line (2 adults, 2026-10-20 to 2026-10-22, 200.00) as the company user and return the reservation.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 */
function portalMakeBooking(array $setup, array $overrides = []): Reservation
{
    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], $overrides))->assertSessionHasNoErrors();

    return Reservation::query()->latest('id')->firstOrFail();
}
