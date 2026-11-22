<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\PropertyStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Group\GroupDashboard;
use App\Services\Platform\Impersonation;
use App\Services\Staff\GroupProperties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Both lodges live, with settings, a business date of 2026-10-14 and a room type of three rooms each.
 *
 * @return array{sepilok: Property, kinabatangan: Property, sepilokOwner: User, kinabatanganOwner: User, manager: User, frontDesk: User, roomTypes: array<string, RoomType>}
 */
function liveGroupSetup(): array
{
    $setup = groupSetup();
    $roomTypes = [];

    foreach (['sepilok', 'kinabatangan'] as $key) {
        $property = $setup[$key];
        $property->forceFill(['status' => PropertyStatus::Live])->save();
        PropertySetting::factory()->for($property)->create();
        BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
        $roomTypes[$key] = RoomType::factory()->for($property)->create();
        Room::factory()->count(3)->for($property)->create(['room_type_id' => $roomTypes[$key]->id]);
    }

    return [...$setup, 'roomTypes' => $roomTypes];
}

function groupArrival(Property $property, RoomType $roomType): ReservationUnit
{
    return ReservationUnit::factory()
        ->for(Reservation::factory()->create(['property_id' => $property->id]))
        ->withNights()
        ->create(['room_type_id' => $roomType->id, 'arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
}

function groupDashboardUrl(Property $property): string
{
    return route('group.dashboard', $property->property_group_id);
}

test('group mode opens only for staff who may open two or more live properties of the group', function () {
    $setup = liveGroupSetup();
    $url = groupDashboardUrl($setup['sepilok']);

    $this->actingAs($setup['frontDesk'])->get($url)->assertNotFound();
    $this->actingAs(User::factory()->owner()->create())->get($url)->assertNotFound();

    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/dashboard')
            ->where('group.name', 'Tanini')
            ->where('property', null)
            ->where('propertyGroup.name', 'Tanini')
            ->has('propertySwitcher', 2)
            ->where('navigation.contexts.0.key', 'group')
            ->has('navigation.contexts', 1));

    $setup['kinabatangan']->forceFill(['status' => PropertyStatus::Suspended])->save();
    $this->get($url)->assertNotFound();
});

test('the property menu offers the group to staff with two properties in it, and nothing more to the others', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(route('dashboard', $setup['sepilok']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groupSwitcher', [['id' => $setup['sepilok']->property_group_id, 'name' => 'Tanini']])
            ->where('propertyGroup', null));
    $this->actingAs($setup['frontDesk'])->get(route('dashboard', $setup['sepilok']->code))
        ->assertInertia(fn (Assert $page) => $page->where('groupSwitcher', []));
});

test('each property shows its own figures as the role there allows, and the totals add them up', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Housekeeping);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $this->actingAs($setup['manager'])->get(groupDashboardUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('dashboard.properties.0.property.name', 'Tanini Kinabatangan')
                ->where('dashboard.properties.0.tiles', null)
                ->where('dashboard.properties.1.property.name', 'Tanini Sepilok')
                ->where('dashboard.properties.1.businessDate', '2026-10-14')
                ->where('dashboard.properties.1.tiles.arrivals', ['remaining' => 2, 'total' => 2])
                ->where('dashboard.totals.arrivalsTotal', 2)
                ->where('dashboard.totals.unitsSold', 2)
                ->where('dashboard.totals.unitsOnSale', 3)
                ->where('dashboard.totals.occupancy', 67)
                ->where('dashboard.totals.revenueProperties', 1)
                ->where('dashboard.totals.properties', 2)));

    grantKinabatangan($setup, $setup['frontDesk'], DefaultRoleEnum::Manager);
    $this->actingAs($setup['frontDesk'])->get(groupDashboardUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('dashboard.totals.arrivalsTotal', 3)
                ->where('dashboard.totals.unitsSold', 3)
                ->where('dashboard.totals.unitsOnSale', 6)
                ->where('dashboard.totals.occupancy', 50)));
});

test('a support session cannot open group mode', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->withSession([Impersonation::SESSION_KEY => [
        'support_user_id' => User::factory()->admin()->create()->id,
        'user_id' => $setup['manager']->id,
        'property_id' => $setup['sepilok']->id,
        'reason' => 'support',
        'note' => null,
        'started_at' => now()->toIso8601String(),
        'return_url' => null,
    ]])->get(groupDashboardUrl($setup['sepilok']))->assertNotFound();
});

test('the group dashboard costs the same queries however many bookings the properties have', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $count = function () use ($setup): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(GroupDashboard::class)->for($setup['manager'], app(GroupProperties::class)->propertiesFor($setup['manager'], $setup['sepilok']->group));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    Model::preventLazyLoading();

    try {
        $count();
        $few = $count();

        foreach (range(1, 3) as $ignored) {
            groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
            groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
        }

        $many = $count();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});
