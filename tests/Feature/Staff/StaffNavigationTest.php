<?php

use App\Features\Boats;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

test('owners get front desk, finance, reports and settings contexts with the active context from the route', function () {
    $staff = User::factory()->owner()->create();
    $code = $staff->property->code;

    $this->actingAs($staff)
        ->get(route('dashboard', $code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'front-desk')
            ->has('navigation.contexts', 4)
            ->where('navigation.contexts.0.key', 'front-desk')
            ->where('navigation.contexts.0.href', "/p/{$code}/dashboard")
            ->where('navigation.contexts.0.groups.0.items.0.active', true)
            ->where('navigation.contexts.1.key', 'finance')
            ->where('navigation.contexts.1.href', "/p/{$code}/finance/city-ledger/companies")
            ->where('navigation.contexts.2.key', 'reports')
            ->where('navigation.contexts.2.href', "/p/{$code}/reports/guest-list")
            ->where('navigation.contexts.3.key', 'settings')
            ->where('navigation.contexts.3.groups.0.items.0.active', false)
        );
});

test('the details item stays active on every settings tab', function (string $routeName) {
    $staff = User::factory()->owner()->create();

    $this->actingAs($staff)
        ->get(route($routeName, $staff->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'settings')
            ->where('navigation.contexts.0.groups.0.items.0.active', false)
            ->where('navigation.contexts.3.groups.0.items.0', [
                'title' => 'Details',
                'href' => '/p/'.$staff->property->code.'/settings/profile',
                'icon' => 'building',
                'external' => false,
                'active' => true,
                'action' => null,
                'badge' => null,
            ])
        );
})->with(['settings.profile', 'settings.business', 'settings.edit', 'settings.operations', 'settings.support']);

test('staff keep their property navigation on account pages with no active context', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', null)
            ->where('navigation.contexts.0.href', '/p/'.$staff->property->code.'/dashboard')
        );
});

test('admins and staff of suspended properties get no staff navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('navigation', null));

    $staff = User::factory()->for(Property::factory()->suspended())->create();

    $this->actingAs($staff)
        ->get(route('property.suspended', $staff->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('navigation', null));
});

test('front desk navigation puts house status and Boat Trips under rooms, and no Boat manifest entry', function () {
    $staff = User::factory()->owner()->create();
    $code = $staff->property->code;

    $this->actingAs($staff)
        ->get(route('dashboard', $code))
        ->assertInertia(function (Assert $page) use ($code) {
            $rooms = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Rooms');
            $guests = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Guests');

            expect(collect($rooms['items'])->pluck('title')->all())->toBe(['Room blocks', 'Housekeeping', 'House status'])
                ->and($rooms['items'][2])->toMatchArray([
                    'title' => 'House status',
                    'href' => "/p/{$code}/reports/house-status",
                    'icon' => 'layout-grid',
                ])
                ->and(collect($guests['items'])->pluck('title')->all())->toBe(['Guests']);
        });

    Feature::for($staff->property)->activate(Boats::class);

    $this->actingAs($staff)
        ->get(route('dashboard', $code))
        ->assertInertia(function (Assert $page) use ($code) {
            $rooms = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Rooms');
            $guests = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Guests');

            expect(collect($rooms['items'])->pluck('title')->all())->toBe(['Room blocks', 'Housekeeping', 'Boat Trips', 'House status'])
                ->and($rooms['items'][2])->toMatchArray([
                    'title' => 'Boat Trips',
                    'href' => "/p/{$code}/boats",
                    'icon' => 'ship',
                ])
                ->and(collect($guests['items'])->pluck('title')->all())->toBe(['Guests']);
        });
});

test('house status and Boat Trips keep front-desk navigation context and mark their items active', function () {
    $staff = User::factory()->owner()->create();
    $property = $staff->property;
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-20']);
    Feature::for($property)->activate(Boats::class);
    $code = $property->code;

    $this->actingAs($staff)
        ->get(route('reports.house-status', $code))
        ->assertInertia(function (Assert $page) {
            $rooms = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Rooms');
            $houseStatusItem = collect($rooms['items'])->firstWhere('title', 'House status');

            expect($page->toArray()['props']['navigation']['active'])->toBe('front-desk')
                ->and($houseStatusItem['active'])->toBeTrue();
        });

    $this->actingAs($staff)
        ->get(route('boats.index', $code))
        ->assertInertia(function (Assert $page) {
            $rooms = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Rooms');
            $boatTripsItem = collect($rooms['items'])->firstWhere('title', 'Boat Trips');

            expect($page->toArray()['props']['navigation']['active'])->toBe('front-desk')
                ->and($boatTripsItem['active'])->toBeTrue();
        });
});
