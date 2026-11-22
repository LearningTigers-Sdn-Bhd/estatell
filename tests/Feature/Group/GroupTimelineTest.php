<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Services\Group\GroupTimeline;
use App\Services\Staff\GroupProperties;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('the group timeline shows every property whose role shows the front desk, for the same dates', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Housekeeping);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    $url = route('group.timeline', $setup['sepilok']->property_group_id);

    $this->actingAs($setup['manager'])->get($url.'?from=2026-10-14&to=2026-10-20')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/timeline')
            ->where('from', '2026-10-14')
            ->where('to', '2026-10-20')
            ->has('properties', 1)
            ->where('properties.0.property.name', 'Tanini Sepilok')
            ->where('properties.0.canViewReservations', true)
            ->has('properties.0.rooms', 3)
            ->has('properties.0.stays', 1));

    grantKinabatangan($setup, $setup['frontDesk'], DefaultRoleEnum::FrontDesk);
    $this->actingAs($setup['frontDesk'])->get($url)
        ->assertInertia(fn (Assert $page) => $page->has('properties', 2));
    $this->get($url.'?from=2026-10-01&to=2026-11-30')->assertSessionHasErrors('to');
});

test('the group timeline costs the same queries however many stays the properties have', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $from = CarbonImmutable::parse('2026-10-14');
    $count = function () use ($setup, $from): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(GroupTimeline::class)->for($setup['manager'], app(GroupProperties::class)->propertiesFor($setup['manager'], $setup['sepilok']->group), $from, $from->addDays(13));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // A property with no stays skips the eager loads of its stays, so both start with one.
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
    Model::preventLazyLoading();

    try {
        $count();
        $few = $count();

        foreach (range(1, 2) as $ignored) {
            groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
            groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
        }

        $many = $count();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});
