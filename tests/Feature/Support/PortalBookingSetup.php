<?php

require_once __DIR__.'/BookingSetup.php';
require_once __DIR__.'/PortalSetup.php';

use App\Enums\CompanyAccess;
use App\Enums\PolicyScope;
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
        $setup[$key]->forceFill(['company_access' => CompanyAccess::All, 'company_in_portal' => true])->save();
    }

    /** @var User $user */
    $user = portalUser();
    /** @var array{0: Company, 1: CompanyUser} $linked */
    $linked = portalLinkFor($user, $property);

    return [...$setup, 'portalUser' => $user, 'company' => $linked[0], 'link' => $linked[1]];
}

/**
 * A line for the Basic plan and the Room room type, 2026-10-20 to 2026-10-22, 2 adults, one room.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function portalRoomLine(array $setup, array $line = []): array
{
    return ['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'adults' => 2, 'child_ages' => [], ...$line];
}

/**
 * A line for the dorm plan and the dorm, with its beds.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function portalDormLine(array $setup, int $beds, array $line = []): array
{
    return ['rate_plan_id' => $setup['bedPlan']->id, 'room_type_id' => $setup['dorm']->id, 'adults' => 1, 'beds' => $beds, ...$line];
}

/**
 * A full booking body: one guest for each unit of the lines (each room, then each bed), named "Guest 1", "Guest 2",
 * and so on, unless the overrides give `guests`.
 *
 * @param  array<string, mixed>  $setup
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function portalBookingBody(array $setup, array $lines, array $overrides = []): array
{
    $units = array_sum(array_map(fn (array $line): int => $line['room_type_id'] === $setup['dorm']->id ? (int) ($line['beds'] ?? 1) : (int) ($line['rooms'] ?? 1), $lines));
    $guests = array_map(fn (int $index): array => ['full_name' => 'Guest '.($index + 1)], range(0, $units - 1));

    return ['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22', 'lines' => $lines, 'guests' => $guests, 'expected_total' => '200.00', ...$overrides];
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
    return test()->actingAs($setup['portalUser'])->from(route('portal.bookings.create', $setup['property']))->post(route('portal.bookings.store', $setup['property']), $body);
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
