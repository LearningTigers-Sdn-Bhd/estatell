<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\PropertyStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use App\Services\Group\GroupDashboard;
use App\Services\Platform\Impersonation;
use App\Services\Staff\GroupProperties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

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

test('sign-in lands back in group mode after working there, and on the property after opening one, not after a hover prefetch', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $this->actingAs($setup['manager']);

    $this->get(groupDashboardUrl($setup['sepilok']))->assertOk();
    $this->withHeaders(['Purpose' => 'prefetch'])->get(route('dashboard', $setup['kinabatangan']->code))->assertOk();
    $this->flushHeaders();
    $this->get(route('property.logo', $setup['kinabatangan']->code), ['Accept' => 'image/avif,image/webp,*/*']);
    $this->get(route('home.redirect'))->assertRedirect(groupDashboardUrl($setup['sepilok']));

    $this->get(route('dashboard', $setup['kinabatangan']->code))->assertOk();
    expect($setup['manager']->fresh()->last_property_group_id)->toBeNull();
    $this->get(route('home.redirect'))->assertRedirect(route('dashboard', $setup['kinabatangan']->code));
});

test('an admin viewing a manager as staff opens group mode with the support banner, and a partner of another property cannot', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $session = fn (User $support): array => [Impersonation::SESSION_KEY => [
        'support_user_id' => $support->id,
        'user_id' => $setup['manager']->id,
        'property_id' => $setup['sepilok']->id,
        'reason' => 'support',
        'note' => null,
        'started_at' => now()->toIso8601String(),
        'return_url' => null,
    ]];

    $this->actingAs($setup['manager'])->withSession($session(User::factory()->admin()->create()))
        ->get(groupDashboardUrl($setup['sepilok']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('impersonation.propertyCode', $setup['sepilok']->code));

    $partner = Partner::factory()->create();
    $setup['sepilok']->forceFill(['partner_id' => $partner->id])->save();
    $this->actingAs($setup['manager'])->withSession($session(User::factory()->partner($partner)->create()))
        ->get(groupDashboardUrl($setup['sepilok']))
        ->assertNotFound();
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

test('a property whose business date is behind shows the night audit alert, and the next six months load on their own', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $this->travelTo('2026-10-17 12:00:00');
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);

    $this->actingAs($setup['manager'])->get(groupDashboardUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('forecast')
            ->loadDeferredProps('forecast', fn (Assert $page) => $page
                ->has('forecast.months', 6)
                ->where('forecast.months.0', '2026-10')
                ->has('forecast.properties', 2))
            ->loadDeferredProps('default', fn (Assert $page) => $page
                ->where('dashboard.properties.1.property.name', 'Tanini Sepilok')
                ->where('dashboard.properties.1.alert', 'The business date is 14 Oct. Run the night audit to catch up.')));
});
