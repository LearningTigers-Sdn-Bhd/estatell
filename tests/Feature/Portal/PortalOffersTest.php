<?php

use App\Enums\CompanyAccess;
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
    return test()->actingAs($setup['portalUser'])->postJson(route('portal.bookings.offers', $setup['property']), [
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'lines' => $lines,
        ...$overrides,
    ]);
}

test('the book page lists only room types that a visible plan sells', function () {
    $setup = portalBookingSetup();
    $setup['bedPlan']->forceFill(['company_access' => CompanyAccess::Everyone])->save();

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn ($page) => $page
            ->component('portal/bookings/create')
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
    $setup['plan']->forceFill(['company_access' => CompanyAccess::Everyone])->save();
    $arrange($plan, $setup['company'], $other);

    $ids = collect(portalOffers($setup, [portalRoomLine($setup)])->json('lines.0.offers'))->pluck('ratePlanId')->all();

    expect(in_array($plan->id, $ids, true))->toBe($visible);
})->with([
    'everyone' => [fn ($plan) => null, false],
    'all, portal on' => [fn ($plan) => $plan->forceFill(['company_access' => CompanyAccess::All, 'company_in_portal' => true])->save(), true],
    'all, front desk only' => [fn ($plan) => $plan->forceFill(['company_access' => CompanyAccess::All, 'company_at_desk' => true, 'company_in_portal' => false])->save(), false],
    'selected, this company with portal on' => [function ($plan, $company) {
        $plan->forceFill(['company_access' => CompanyAccess::Selected])->save();
        $plan->companies()->attach($company, ['at_desk' => false, 'in_portal' => true]);
    }, true],
    'selected, this company front desk only' => [function ($plan, $company) {
        $plan->forceFill(['company_access' => CompanyAccess::Selected])->save();
        $plan->companies()->attach($company, ['at_desk' => true, 'in_portal' => false]);
    }, false],
    'selected, another company only' => [function ($plan, $company, $other) {
        $plan->forceFill(['company_access' => CompanyAccess::Selected])->save();
        $plan->companies()->attach($other, ['at_desk' => true, 'in_portal' => true]);
    }, false],
    'selected, nobody listed' => [function ($plan) {
        $plan->forceFill(['company_access' => CompanyAccess::Selected])->save();
    }, false],
    'archived' => [function ($plan) {
        $plan->forceFill(['company_access' => CompanyAccess::All, 'archived_at' => now()])->save();
    }, false],
]);

test('a Day use plan is never offered', function () {
    $setup = portalBookingSetup();
    $dayUse = RatePlan::factory()->for($setup['property'])->dayUse()->create(['company_access' => CompanyAccess::All]);
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
    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn ($page) => $page->where('companySuspended', true)->has('roomTypes', 0));
});

test('staff and users without an Active link cannot search', function () {
    $setup = portalBookingSetup();

    $this->actingAs($setup['user'])->postJson(route('portal.bookings.offers', $setup['property']), [])->assertNotFound();
    $this->actingAs(User::factory()->companyUser()->create())->postJson(route('portal.bookings.offers', $setup['property']), [])->assertNotFound();
});
