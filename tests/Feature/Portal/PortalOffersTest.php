<?php

use App\Enums\PortalAccess;
use App\Models\Company;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Support/PortalBookingSetup.php';

/**
 * @param  array<string, mixed>  $setup
 * @param  list<array<string, mixed>>  $lines
 */
function portalOffers(array $setup, array $lines, array $overrides = []): TestResponse
{
    return test()->actingAs($setup['portalUser'])->postJson(route('portal.book.offers', $setup['property']), [
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'lines' => $lines,
        ...$overrides,
    ]);
}

test('the book page lists only room types that a visible plan sells', function () {
    $setup = portalBookingSetup();
    $setup['bedPlan']->forceFill(['portal_access' => PortalAccess::Hidden])->save();

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.book', $setup['property']))
        ->assertInertia(fn ($page) => $page
            ->component('portal/book')
            ->where('companySuspended', false)
            ->where('today', '2026-10-14')
            ->has('roomTypes', 1)
            ->where('roomTypes.0.id', $setup['roomType']->id));
});

test('an offer is the visible plan with its price', function () {
    $setup = portalBookingSetup();

    portalOffers($setup, [portalRoomLine($setup)])
        ->assertOk()
        ->assertJsonPath('lines.0.offers.0.ratePlanId', $setup['plan']->id)
        ->assertJsonPath('lines.0.offers.0.total', '200.00')
        ->assertJsonPath('lines.0.offers.0.reason', null)
        ->assertJsonPath('lines.0.lineError', null);
});

test('the offer total includes the auto-added items', function () {
    $setup = portalBookingSetup();
    portalAutoAddOn($setup['property']);

    portalOffers($setup, [portalRoomLine($setup)])->assertJsonPath('lines.0.offers.0.total', '210.00');
});

test('a dorm line is priced for each bed', function () {
    $setup = portalBookingSetup();

    portalOffers($setup, [portalDormLine($setup, 3)])
        ->assertJsonPath('lines.0.offers.0.ratePlanId', $setup['bedPlan']->id)
        ->assertJsonPath('lines.0.offers.0.total', '240.00');
});

test('plan visibility follows portal access and company links', function (callable $arrange, bool $visible) {
    $setup = portalBookingSetup();
    $other = Company::factory()->for($setup['property'])->create();
    $plan = RatePlan::factory()->for($setup['property'])->create([
        'parent_rate_plan_id' => $setup['plan']->id, 'derivation' => 'percent', 'derivation_value' => '-10',
    ]);
    $plan->roomTypes()->attach($setup['roomType']);
    foreach (range(0, 6) as $night) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $setup['roomType']->id, 'date' => '2026-10-'.(20 + $night), 'amount' => '90.00']);
    }
    $setup['plan']->forceFill(['portal_access' => PortalAccess::Hidden])->save();
    $arrange($plan, $setup['company'], $other);

    $ids = collect(portalOffers($setup, [portalRoomLine($setup)])->json('lines.0.offers'))->pluck('ratePlanId')->all();

    expect(in_array($plan->id, $ids, true))->toBe($visible);
})->with([
    'hidden' => [fn ($plan) => null, false],
    'all, open to everyone' => [fn ($plan) => $plan->forceFill(['portal_access' => PortalAccess::All])->save(), true],
    'all, linked to another company' => [function ($plan, $company, $other) {
        $plan->forceFill(['portal_access' => PortalAccess::All])->save();
        $plan->companies()->attach($other);
    }, false],
    'all, linked to this company only' => [function ($plan, $company) {
        $plan->forceFill(['portal_access' => PortalAccess::All])->save();
        $plan->companies()->attach($company);
    }, true],
    'selected, linked to this company' => [function ($plan, $company) {
        $plan->forceFill(['portal_access' => PortalAccess::Selected])->save();
        $plan->companies()->attach($company);
    }, true],
    'selected, linked to another company' => [function ($plan, $company, $other) {
        $plan->forceFill(['portal_access' => PortalAccess::Selected])->save();
        $plan->companies()->attach($other);
    }, false],
    'archived' => [function ($plan) {
        $plan->forceFill(['portal_access' => PortalAccess::All, 'archived_at' => now()])->save();
    }, false],
]);

test('a Day use plan is never offered', function () {
    $setup = portalBookingSetup();
    $dayUse = RatePlan::factory()->for($setup['property'])->dayUse()->create(['portal_access' => PortalAccess::All]);
    $dayUse->roomTypes()->attach($setup['roomType']);

    $ids = collect(portalOffers($setup, [portalRoomLine($setup)])->json('lines.0.offers'))->pluck('ratePlanId')->all();

    expect($ids)->toBe([$setup['plan']->id]);
});

test('a blocked offer is shown with its reason', function (string $case, string $reason) {
    $setup = portalBookingSetup();

    match ($case) {
        'closed' => Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-20')->update(['closed_to_arrival' => true]),
        'min stay' => Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-20')->update(['min_stay' => 3]),
        'no rate' => Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-21')->delete(),
        'sold out' => collect($setup['rooms'])->each(fn ($room) => $room->forceFill(['archived_at' => now()])->save()),
    };

    $offer = portalOffers($setup, [portalRoomLine($setup)])->json('lines.0.offers.0');

    expect($offer['ratePlanId'])->toBe($setup['plan']->id)
        ->and($offer['reason'])->toContain($reason);
})->with([
    ['closed', 'Closed to arrival on 20 Oct 2026'],
    ['min stay', 'Min stay 3 nights'],
    ['no rate', 'No rate is set'],
    ['sold out', 'Not available on 20 Oct 2026'],
]);

test('too many adults or children stop the line', function (array $line, string $message) {
    $setup = portalBookingSetup();

    portalOffers($setup, [portalRoomLine($setup, $line)])
        ->assertOk()
        ->assertJsonPath('lines.0.offers', [])
        ->assertJsonPath('lines.0.lineError', $message);
})->with([
    'adults' => [['adults' => 3], 'Too many adults for this room type.'],
    'children' => [['child_ages' => [4, 6]], 'Too many children for this room type.'],
]);

test('a search needs every child age and a valid stay', function (array $overrides, string $field) {
    $setup = portalBookingSetup();

    portalOffers($setup, [portalRoomLine($setup, ['child_ages' => [null]])], $overrides)->assertJsonValidationErrors([$field]);
})->with([
    'age' => [[], 'lines.0.child_ages.0'],
    'before the business date' => [['arrival_date' => '2026-10-13', 'departure_date' => '2026-10-15'], 'arrival_date'],
    'departure first' => [['departure_date' => '2026-10-19'], 'departure_date'],
]);

test('a search takes up to 20 lines', function () {
    $setup = portalBookingSetup();

    portalOffers($setup, array_fill(0, 21, portalRoomLine($setup)))->assertJsonValidationErrors(['lines']);
    portalOffers($setup, array_fill(0, 20, portalRoomLine($setup)))->assertOk()->assertJsonCount(20, 'lines');
});

test('a Suspended company cannot search and sees the page without room types', function () {
    $setup = portalBookingSetup();
    $setup['company']->forceFill(['status' => 'suspended'])->save();

    portalOffers($setup, [portalRoomLine($setup)])->assertForbidden();
    $this->actingAs($setup['portalUser'])->get(route('portal.book', $setup['property']))
        ->assertInertia(fn ($page) => $page->where('companySuspended', true)->has('roomTypes', 0));
});

test('staff and users without an Active link cannot search', function () {
    $setup = portalBookingSetup();

    $this->actingAs($setup['user'])->postJson(route('portal.book.offers', $setup['property']), [])->assertNotFound();
    $this->actingAs(User::factory()->companyUser()->create())->postJson(route('portal.book.offers', $setup['property']), [])->assertNotFound();
});
