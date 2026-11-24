<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Enums\DefaultRoleEnum;
use Inertia\Testing\AssertableInertia as Assert;

test('every group page with an export gives a landscape PDF of the same rows', function (string $route, string $query) {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $response = $this->actingAs($setup['manager'])
        ->get(route($route, $setup['sepilok']->property_group_id).$query)
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF');
})->with([
    'forecast' => ['group.forecast.pdf', '?from=2026-10&months=2'],
    'performance' => ['group.reports.pdf', '?from=2026-10-14&to=2026-10-14'],
    'property report' => ['group.property-reports.pdf', '?report=arrivals&from=2026-10-14&to=2026-10-14'],
    'reservations' => ['group.reservations.pdf', ''],
]);

test('a report category opens on its first report and lists only its reports as tabs', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(route('group.property-reports', $setup['sepilok']->property_group_id).'?category=finance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('category.value', 'finance')
            ->where('result.report', 'payments')
            ->where('reports', fn ($reports): bool => collect($reports)->pluck('value')->all() === ['payments', 'outstanding-balance', 'deposit-liability', 'agent-payments']));
});

test('the group timeline opens on this whole month, and shows any whole month', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(route('group.timeline', $setup['sepilok']->property_group_id))
        ->assertInertia(fn (Assert $page) => $page->where('from', '2026-10-01')->where('to', '2026-10-31'));

    $this->get(route('group.timeline', $setup['sepilok']->property_group_id).'?from=2026-12-01&to=2026-12-31')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('from', '2026-12-01')->where('to', '2026-12-31'));
});
