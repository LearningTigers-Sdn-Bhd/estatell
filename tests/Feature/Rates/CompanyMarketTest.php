<?php

use App\Actions\Rates\SetRatePlanCompanyAccess;
use App\Enums\CompanyAccess;
use App\Enums\CompanyMarket;
use App\Enums\CompanyRateMarket;
use App\Enums\DefaultRoleEnum;
use App\Models\Company;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Portal\PortalRatePlans;
use App\Services\Rates\CompanyRateAccess;
use App\Services\Rates\CompanyRatePlans;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * An Owner, a property and a plan open to all companies.
 *
 * @return array{user: User, property: Property, plan: RatePlan}
 */
function marketSetup(CompanyRateMarket $market = CompanyRateMarket::All, CompanyAccess $access = CompanyAccess::All): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create([
        'name' => 'Agents',
        'company_access' => $access,
        'company_market' => $market,
    ])->refresh();

    return ['user' => $user, 'property' => $property, 'plan' => $plan];
}

test('a plan market is offered to the companies of that market', function (CompanyRateMarket $plan, ?CompanyMarket $company, bool $offered) {
    expect($plan->offersTo($company))->toBe($offered);
})->with([
    'all, local company' => [CompanyRateMarket::All, CompanyMarket::Local, true],
    'all, no market' => [CompanyRateMarket::All, null, true],
    'local, local company' => [CompanyRateMarket::Local, CompanyMarket::Local, true],
    'local, international company' => [CompanyRateMarket::Local, CompanyMarket::International, false],
    'local, no market' => [CompanyRateMarket::Local, null, false],
    'international, international company' => [CompanyRateMarket::International, CompanyMarket::International, true],
    'international, local company' => [CompanyRateMarket::International, CompanyMarket::Local, false],
]);

test('the front desk offers a market plan only to a company in that market', function (CompanyAccess $access) {
    $setup = marketSetup(CompanyRateMarket::Local, $access);
    $local = Company::factory()->for($setup['property'])->inMarket(CompanyMarket::Local)->create();
    $international = Company::factory()->for($setup['property'])->inMarket(CompanyMarket::International)->create();
    $unset = Company::factory()->for($setup['property'])->create();
    $setup['plan']->companies()->attach([$local->id, $international->id, $unset->id]);
    $access = app(CompanyRateAccess::class);

    expect($access->deskAllows($setup['plan'], $local->id))->toBeTrue()
        ->and($access->deskAllows($setup['plan'], $international->id))->toBeFalse()
        ->and($access->deskAllows($setup['plan'], $unset->id))->toBeFalse()
        ->and($access->usableBy($setup['plan'], $local->id))->toBeTrue()
        ->and($access->usableBy($setup['plan'], $international->id))->toBeFalse()
        ->and($access->usableBy($setup['plan'], $unset->id))->toBeFalse();
})->with([CompanyAccess::All, CompanyAccess::Selected]);

test('a plan open to every market is offered to a company with no market', function () {
    $setup = marketSetup();
    $unset = Company::factory()->for($setup['property'])->create();

    expect(app(CompanyRateAccess::class)->deskAllows($setup['plan'], $unset->id))->toBeTrue();
});

test('the portal shows a market plan only to a company in that market', function () {
    $setup = marketSetup(CompanyRateMarket::International);
    $international = Company::factory()->for($setup['property'])->inMarket(CompanyMarket::International)->create();
    $local = Company::factory()->for($setup['property'])->inMarket(CompanyMarket::Local)->create();
    $unset = Company::factory()->for($setup['property'])->create();
    $portal = app(PortalRatePlans::class);

    expect($portal->isVisible($international, $setup['plan']->id))->toBeTrue()
        ->and($portal->isVisible($local, $setup['plan']->id))->toBeFalse()
        ->and($portal->isVisible($unset, $setup['plan']->id))->toBeFalse();
});

test('the Rates tab leaves out plans of another market', function () {
    $setup = marketSetup(CompanyRateMarket::Local);
    $open = RatePlan::factory()->for($setup['property'])->create(['name' => 'Open', 'company_access' => CompanyAccess::All]);
    $local = Company::factory()->for($setup['property'])->inMarket(CompanyMarket::Local)->create();
    $international = Company::factory()->for($setup['property'])->inMarket(CompanyMarket::International)->create();
    $names = fn (Company $company): array => array_map(fn ($rate) => $rate->name, app(CompanyRatePlans::class)->forCompany($company));

    expect($names($local))->toEqualCanonicalizing(['Agents', 'Open'])
        ->and($names($international))->toBe(['Open']);

    $this->actingAs($setup['user'])
        ->get("/p/{$setup['property']->code}/finance/city-ledger/companies/{$international->id}/rates")
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.market', 'international')
            ->where('company.marketLabel', 'International')
            ->has('rates', 1));
    expect($open->refresh()->company_market)->toBe(CompanyRateMarket::All);
});

test('the booking options list the companies of a market plan', function () {
    $setup = marketSetup(CompanyRateMarket::Local);
    $local = Company::factory()->for($setup['property'])->inMarket(CompanyMarket::Local)->create(['name' => 'Alpha']);
    Company::factory()->for($setup['property'])->inMarket(CompanyMarket::International)->create(['name' => 'Zeta']);

    $this->actingAs($setup['user'])
        ->get(route('reservations.new', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('addUnitOptions.ratePlans.0.companyMarket', 'local')
            ->where('addUnitOptions.ratePlans.0.marketCompanyIds', [$local->id])
            ->where('companies.0.marketLabel', 'Local'));
});

test('the database checks the market values', function () {
    $setup = marketSetup();
    $company = Company::factory()->for($setup['property'])->create();

    expect(fn () => DB::table('companies')->where('id', $company->id)->update(['market' => 'asia']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('rate_plans')->where('id', $setup['plan']->id)->update(['company_market' => 'asia']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('rate_plans')->where('id', $setup['plan']->id)->update(['company_access' => 'everyone', 'company_market' => 'local']))->toThrow(QueryException::class);
});

test('setting the access saves the market, keeps it when none is sent and resets it for Everyone', function () {
    $setup = marketSetup();
    $set = app(SetRatePlanCompanyAccess::class);

    expect($set($setup['user'], $setup['plan'], CompanyAccess::All, true, true, CompanyRateMarket::International))->toBeNull()
        ->and($setup['plan']->refresh()->company_market)->toBe(CompanyRateMarket::International);

    $activity = Activity::query()->where('event', 'rate_plan.company_access_updated')->latest('id')->first();
    expect($activity->properties['from']['market'])->toBe('all')
        ->and($activity->properties['to']['market'])->toBe('international');

    expect($set($setup['user'], $setup['plan'], CompanyAccess::All, false, true))->toBeNull()
        ->and($setup['plan']->refresh()->company_market)->toBe(CompanyRateMarket::International);

    expect($set($setup['user'], $setup['plan'], CompanyAccess::Everyone))->toBeNull()
        ->and($setup['plan']->refresh()->company_market)->toBe(CompanyRateMarket::All);
});

test('a market change alone is saved and logged, and the same market is not', function () {
    $setup = marketSetup();
    $set = app(SetRatePlanCompanyAccess::class);

    $set($setup['user'], $setup['plan'], CompanyAccess::All, true, true, CompanyRateMarket::Local);
    $set($setup['user'], $setup['plan'], CompanyAccess::All, true, true, CompanyRateMarket::Local);

    expect(Activity::query()->where('event', 'rate_plan.company_access_updated')->count())->toBe(1);
});

test('the Companies tab saves the market and sends the options', function () {
    $setup = marketSetup();
    $params = ['property' => $setup['property'], 'ratePlan' => $setup['plan']];

    $this->actingAs($setup['user'])
        ->put(route('settings.rate-plans.companies.update', $params), ['company_access' => 'all', 'company_market' => 'local'])
        ->assertSessionHasNoErrors();

    expect($setup['plan']->refresh()->company_market)->toBe(CompanyRateMarket::Local);

    $this->get(route('settings.rate-plans.companies.edit', $params))
        ->assertInertia(fn (Assert $page) => $page
            ->where('access.companyMarket', 'local')
            ->where('marketOptions', CompanyRateMarket::options()));

    $this->put(route('settings.rate-plans.companies.update', $params), ['company_access' => 'all', 'company_market' => 'asia'])
        ->assertSessionHasErrors('company_market');
});

test('the company page saves the market and clears it', function () {
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel']);
    $base = "/p/{$property->code}/finance/city-ledger/companies";
    $details = ['name' => 'Borneo Travel', 'type' => 'travel_agent', 'contact_email' => null, 'contact_phone' => null];

    $this->actingAs($user)->put("{$base}/{$company->id}/details", [...$details, 'market' => 'international'])->assertSessionHasNoErrors();
    expect($company->refresh()->market)->toBe(CompanyMarket::International);

    $this->put("{$base}/{$company->id}/details", [...$details, 'market' => ''])->assertSessionHasNoErrors();
    expect($company->refresh()->market)->toBeNull();

    $this->put("{$base}/{$company->id}/details", [...$details, 'market' => 'asia'])->assertSessionHasErrors('market');
});

test('a company is added with a market', function () {
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;

    $this->actingAs($user)->post("/p/{$property->code}/finance/city-ledger/companies", [
        'name' => 'Sabah Tours', 'type' => 'travel_agent', 'market' => 'local', 'direct_bill_enabled' => true,
    ])->assertSessionHasNoErrors();

    expect(Company::query()->where('name', 'Sabah Tours')->value('market'))->toBe(CompanyMarket::Local);
});
