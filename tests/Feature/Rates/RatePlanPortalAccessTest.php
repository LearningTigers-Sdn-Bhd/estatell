<?php

use App\Actions\CityLedger\SyncCompanyRatePlans;
use App\Actions\Rates\UpdateRatePlanPortalAccess;
use App\Enums\DefaultRoleEnum;
use App\Enums\PortalAccess;
use App\Enums\RateDerivation;
use App\Models\Company;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A property with an Owner, a Basic plan and a derived Corporate plan.
 *
 * @return array{user: User, property: Property, basic: RatePlan, derived: RatePlan}
 */
function portalAccessSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $basic = RatePlan::factory()->for($property)->create(['name' => 'Basic Rate']);
    $derived = RatePlan::factory()->for($property)->create([
        'name' => 'Corporate',
        'parent_rate_plan_id' => $basic->id,
        'derivation' => RateDerivation::Percent,
        'derivation_value' => '-15',
    ]);

    return ['user' => $user, 'property' => $property, 'basic' => $basic, 'derived' => $derived];
}

test('a new plan is Hidden in the portal', function () {
    $setup = portalAccessSetup();

    expect($setup['basic']->refresh()->portal_access)->toBe(PortalAccess::Hidden);
});

test('the database only accepts the three portal access values', function () {
    $setup = portalAccessSetup();

    expect(fn () => DB::table('rate_plans')->where('id', $setup['basic']->id)->update(['portal_access' => 'everyone']))
        ->toThrow(QueryException::class);
});

test('the Portal tab shows the access, the options and the linked companies', function () {
    $setup = portalAccessSetup();
    $setup['derived']->forceFill(['portal_access' => PortalAccess::Selected])->save();
    $zeta = Company::factory()->for($setup['property'])->create(['name' => 'Zeta Travel']);
    $alpha = Company::factory()->for($setup['property'])->create(['name' => 'Alpha Corp']);
    $setup['derived']->companies()->attach([$zeta->id, $alpha->id]);

    $this->actingAs($setup['user'])
        ->get(route('settings.rate-plans.portal.edit', ['property' => $setup['property'], 'ratePlan' => $setup['derived']]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/rate-plans/portal')
            ->where('portal.portalAccess', 'selected')
            ->where('portal.isDerived', true)
            ->where('portal.companies.0.name', 'Alpha Corp')
            ->where('portal.companies.1.name', 'Zeta Travel')
            ->where('accessOptions', PortalAccess::options())
            ->where('canViewCompanies', true));
});

test('the Portal tab is a 404 for a Day use plan', function () {
    $setup = portalAccessSetup();
    $dayUse = RatePlan::factory()->for($setup['property'])->dayUse()->create();

    $this->actingAs($setup['user'])->get(route('settings.rate-plans.portal.edit', ['property' => $setup['property'], 'ratePlan' => $dayUse]))->assertNotFound();
    $this->actingAs($setup['user'])->put(route('settings.rate-plans.portal.update', ['property' => $setup['property'], 'ratePlan' => $dayUse]), ['portal_access' => 'all'])->assertNotFound();
    expect($dayUse->refresh()->portal_access)->toBe(PortalAccess::Hidden);
});

test('settings.manage opens the tab and saves it', function () {
    $setup = portalAccessSetup();
    $params = ['property' => $setup['property'], 'ratePlan' => $setup['basic']];

    $this->actingAs($setup['user'])
        ->put(route('settings.rate-plans.portal.update', $params), ['portal_access' => 'all'])
        ->assertRedirect(route('settings.rate-plans.portal.edit', $params))
        ->assertInertiaFlash('toast.type', 'success');

    $log = Activity::query()->where('event', 'rate_plan.portal_access_updated')->sole();

    expect($setup['basic']->refresh()->portal_access)->toBe(PortalAccess::All)
        ->and($log->properties['from'])->toBe('hidden')
        ->and($log->properties['to'])->toBe('all')
        ->and($log->causer_id)->toBe($setup['user']->id);
});

test('saving the same access logs nothing', function () {
    $setup = portalAccessSetup();

    expect(app(UpdateRatePlanPortalAccess::class)($setup['user'], $setup['basic'], PortalAccess::Hidden))->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('staff without settings.manage cannot open or save the tab', function () {
    $setup = portalAccessSetup(DefaultRoleEnum::FrontDesk);
    $params = ['property' => $setup['property'], 'ratePlan' => $setup['basic']];

    $this->actingAs($setup['user'])->get(route('settings.rate-plans.portal.edit', $params))->assertForbidden();
    $this->actingAs($setup['user'])->put(route('settings.rate-plans.portal.update', $params), ['portal_access' => 'all'])->assertForbidden();
    expect($setup['basic']->refresh()->portal_access)->toBe(PortalAccess::Hidden);
});

test('another property\'s plan is a 404', function () {
    $setup = portalAccessSetup();
    $foreign = RatePlan::factory()->create();

    $this->actingAs($setup['user'])->get(route('settings.rate-plans.portal.edit', ['property' => $setup['property'], 'ratePlan' => $foreign]))->assertNotFound();
});

test('Selected needs a linked company', function () {
    $setup = portalAccessSetup();
    $params = ['property' => $setup['property'], 'ratePlan' => $setup['derived']];

    $this->actingAs($setup['user'])
        ->put(route('settings.rate-plans.portal.update', $params), ['portal_access' => 'selected'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Link at least one company first.']);

    expect($setup['derived']->refresh()->portal_access)->toBe(PortalAccess::Hidden)
        ->and(Activity::query()->count())->toBe(0);

    $setup['derived']->companies()->attach(Company::factory()->for($setup['property'])->create());

    $this->actingAs($setup['user'])->put(route('settings.rate-plans.portal.update', $params), ['portal_access' => 'selected'])->assertInertiaFlash('toast.type', 'success');
    expect($setup['derived']->refresh()->portal_access)->toBe(PortalAccess::Selected);
});

test('Selected is only for derived plans', function () {
    $setup = portalAccessSetup();

    $this->actingAs($setup['user'])
        ->put(route('settings.rate-plans.portal.update', ['property' => $setup['property'], 'ratePlan' => $setup['basic']]), ['portal_access' => 'selected'])
        ->assertInertiaFlash('toast.type', 'error');

    expect($setup['basic']->refresh()->portal_access)->toBe(PortalAccess::Hidden);
});

test('an archived plan cannot change its portal access', function () {
    $setup = portalAccessSetup();
    $setup['basic']->forceFill(['archived_at' => now()])->save();

    expect(app(UpdateRatePlanPortalAccess::class)($setup['user'], $setup['basic'], PortalAccess::All))->toBe(UpdateRatePlanPortalAccess::REFUSAL_PLAN_CLOSED);
});

test('a company cannot lose the last company link of a Selected plan', function () {
    $setup = portalAccessSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $other = Company::factory()->for($setup['property'])->create();
    $setup['derived']->forceFill(['portal_access' => PortalAccess::Selected])->save();
    $setup['derived']->companies()->attach($company);

    $this->actingAs($setup['user'])
        ->put(route('finance.city-ledger.companies.rates.update', ['property' => $setup['property'], 'company' => $company]), ['rate_plan_ids' => []])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This plan is Selected for the portal and needs a company.']);

    expect($company->ratePlans()->pluck('rate_plans.id')->all())->toBe([$setup['derived']->id])
        ->and(app(SyncCompanyRatePlans::class)($setup['user'], $company, []))->toBeFalse();

    $setup['derived']->companies()->attach($other);

    $this->actingAs($setup['user'])
        ->put(route('finance.city-ledger.companies.rates.update', ['property' => $setup['property'], 'company' => $company]), ['rate_plan_ids' => []])
        ->assertInertiaFlash('toast.type', 'success');

    expect($company->ratePlans()->count())->toBe(0);
});

test('a plan that is not Selected can lose its last company', function () {
    $setup = portalAccessSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['derived']->forceFill(['portal_access' => PortalAccess::All])->save();
    $setup['derived']->companies()->attach($company);

    expect(app(SyncCompanyRatePlans::class)($setup['user'], $company, []))->toBeTrue()
        ->and($company->ratePlans()->count())->toBe(0);
});
