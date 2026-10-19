<?php

use App\Enums\CompanyAccess;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
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

test('the book page lists the visible plans with their room types and summary', function () {
    $setup = portalBookingSetup();
    $setup['bedPlan']->forceFill(['company_access' => CompanyAccess::Everyone])->save();

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn ($page) => $page
            ->component('portal/bookings/create')
            ->where('companySuspended', false)
            ->where('today', '2026-10-14')
            ->has('ratePlans', 1)
            ->where('ratePlans.0.id', $setup['plan']->id)
            ->where('ratePlans.0.summary', 'Room only')
            ->where('ratePlans.0.roomTypeIds', [$setup['roomType']->id])
            ->has('roomTypes', 1)
            ->where('roomTypes.0.id', $setup['roomType']->id)
            ->has('idTypes'));
});

test('a plan summary names its inclusions or its package nights', function () {
    $setup = portalBookingSetup();
    $breakfast = ChargeItem::factory()->for($setup['property'])->create(['name' => 'Breakfast']);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['plan']->id, 'charge_item_id' => $breakfast->id]);
    $setup['bedPlan']->forceFill(['pricing_basis' => PricingBasis::Package, 'package_unit' => PackageUnit::PerGuest, 'fixed_nights' => 3])->save();

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn ($page) => $page
            ->where('ratePlans.0.summary', 'Breakfast')
            ->where('ratePlans.1.summary', '3-night package'));
});

test('an offer is a room type of the plan with its quote and what is left', function () {
    $setup = portalBookingSetup();

    portalOffers($setup, [portalRoomLine($setup)])
        ->assertOk()
        ->assertJsonPath('lines.0.offers.0.roomTypeId', $setup['roomType']->id)
        ->assertJsonPath('lines.0.offers.0.quote.lineTotal', '200.00')
        ->assertJsonPath('lines.0.offers.0.left', 2)
        ->assertJsonPath('lines.0.offers.0.reason', null)
        ->assertJsonPath('lines.0.lineError', null);
});

test('the offer quote has the auto-added items', function () {
    $setup = portalBookingSetup();
    portalAutoAddOn($setup['property']);

    portalOffers($setup, [portalRoomLine($setup)])->assertJsonPath('lines.0.offers.0.quote.addOnsTotal', '10.00');
});

test('a line is priced for its rooms, and a dorm line for each bed', function () {
    $setup = portalBookingSetup();

    portalOffers($setup, [portalRoomLine($setup, ['rooms' => 2]), portalDormLine($setup, 3)])
        ->assertJsonPath('lines.0.offers.0.quote.lineTotal', '400.00')
        ->assertJsonPath('lines.1.offers.0.roomTypeId', $setup['dorm']->id)
        ->assertJsonPath('lines.1.offers.0.quote.lineTotal', '240.00')
        ->assertJsonPath('lines.1.offers.0.left', 3);
});

test('what is left counts the lines above with the same room type', function () {
    $setup = portalBookingSetup();

    $lines = portalOffers($setup, [portalRoomLine($setup), portalRoomLine($setup), portalRoomLine($setup, ['room_type_id' => null])])->json('lines');

    expect($lines[0]['offers'][0]['left'])->toBe(2)
        ->and($lines[1]['offers'][0]['left'])->toBe(1)
        ->and($lines[2]['offers'][0]['left'])->toBe(0)
        ->and($lines[2]['offers'][0]['reason'])->toBe('Not available on 20 Oct 2026.');
});

test('more rooms than are left block the room type', function () {
    $setup = portalBookingSetup();

    portalOffers($setup, [portalRoomLine($setup, ['rooms' => 3])])
        ->assertJsonPath('lines.0.offers.0.reason', 'Only 2 left for these dates.');
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

    $line = portalOffers($setup, [portalRoomLine($setup, ['rate_plan_id' => $plan->id])])->json('lines.0');

    expect($line['lineError'] === null)->toBe($visible)
        ->and($line['offers'] !== [])->toBe($visible);
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

test('a hidden plan stops the line, and a line without a plan has no offers', function () {
    $setup = portalBookingSetup();
    $dayUse = RatePlan::factory()->for($setup['property'])->dayUse()->create(['company_access' => CompanyAccess::All]);
    $dayUse->roomTypes()->attach($setup['roomType']);

    portalOffers($setup, [portalRoomLine($setup, ['rate_plan_id' => $dayUse->id]), portalRoomLine($setup, ['rate_plan_id' => null])])
        ->assertJsonPath('lines.0.offers', [])
        ->assertJsonPath('lines.0.lineError', 'This rate is not available. Pick another rate.')
        ->assertJsonPath('lines.1.offers', [])
        ->assertJsonPath('lines.1.lineError', null);
});

test('a blocked room type is shown with its reason', function (string $case, string $reason) {
    $setup = portalBookingSetup();
    $line = portalRoomLine($setup);

    match ($case) {
        'closed' => Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-20')->update(['closed_to_arrival' => true]),
        'min stay' => Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-20')->update(['min_stay' => 3]),
        'no rate' => Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-21')->delete(),
        'sold out' => collect($setup['rooms'])->each(fn ($room) => $room->forceFill(['archived_at' => now()])->save()),
        'adults' => $line['adults'] = 3,
        'children' => $line['child_ages'] = [4, 6],
    };

    $offer = portalOffers($setup, [$line])->json('lines.0.offers.0');

    expect($offer['roomTypeId'])->toBe($setup['roomType']->id)
        ->and($offer['reason'])->toContain($reason);
})->with([
    ['closed', 'Closed to arrival on 20 Oct 2026'],
    ['min stay', 'Min stay 3 nights'],
    ['no rate', 'No rate is set'],
    ['sold out', 'Not available on 20 Oct 2026'],
    ['adults', 'Too many adults for this room type.'],
    ['children', 'Too many children for this room type.'],
]);

test('a package whose nights differ from the stay blocks its room types', function () {
    $setup = portalBookingSetup();
    $setup['plan']->forceFill(['pricing_basis' => PricingBasis::Package, 'package_unit' => PackageUnit::PerGuest, 'fixed_nights' => 3])->save();

    portalOffers($setup, [portalRoomLine($setup)])
        ->assertJsonPath('lines.0.offers.0.quote', null)
        ->assertJsonPath('lines.0.offers.0.reason', 'This package sells a fixed number of nights.');
});

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
        ->assertInertia(fn ($page) => $page->where('companySuspended', true)->has('ratePlans', 0)->has('roomTypes', 0));
});

test('staff and users without an Active link cannot search', function () {
    $setup = portalBookingSetup();

    $this->actingAs($setup['user'])->postJson(route('portal.bookings.offers', $setup['property']), [])->assertNotFound();
    $this->actingAs(User::factory()->companyUser()->create())->postJson(route('portal.bookings.offers', $setup['property']), [])->assertNotFound();
});
