<?php

use App\Actions\Rates\AddRatePlanCompanies;
use App\Actions\Rates\RemoveRatePlanCompany;
use App\Actions\Rates\SetRatePlanCompanyAccess;
use App\Actions\Rates\UpdateRatePlanCompany;
use App\Enums\CompanyAccess;
use App\Enums\DefaultRoleEnum;
use App\Enums\RateDerivation;
use App\Models\Company;
use App\Models\CompanyUser;
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
function companyAccessSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
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

function companyAccessParams(array $setup, string $plan = 'derived', ?Company $company = null): array
{
    return ['property' => $setup['property'], 'ratePlan' => $setup[$plan], ...($company === null ? [] : ['company' => $company])];
}

test('a new plan is Everyone with both All companies switches on', function () {
    $setup = companyAccessSetup();
    $plan = $setup['basic']->refresh();

    expect($plan->company_access)->toBe(CompanyAccess::Everyone)
        ->and($plan->company_at_desk)->toBeTrue()
        ->and($plan->company_in_portal)->toBeTrue();
});

test('the database checks the access values and that a switch is on', function () {
    $setup = companyAccessSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['derived']->companies()->attach($company);

    expect(fn () => DB::table('rate_plans')->where('id', $setup['basic']->id)->update(['company_access' => 'hidden']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('rate_plans')->where('id', $setup['basic']->id)->update(['company_access' => 'all', 'company_at_desk' => false, 'company_in_portal' => false]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('company_rate_plans')->update(['at_desk' => false, 'in_portal' => false]))->toThrow(QueryException::class);
});

test('the migration moves old portal access and links to the new setting', function () {
    $setup = companyAccessSetup();
    $migration = require database_path('migrations/2026_10_19_120000_replace_portal_access_with_company_access.php');
    $migration->down();

    $make = fn (string $access) => tap(RatePlan::factory()->for($setup['property'])->create(), fn ($plan) => DB::table('rate_plans')->where('id', $plan->id)->update(['portal_access' => $access]));
    $company = Company::factory()->for($setup['property'])->create();
    $linkedSelected = $make('selected');
    $linkedAll = $make('all');
    $linkedHidden = $make('hidden');
    foreach ([$linkedSelected, $linkedAll, $linkedHidden] as $plan) {
        $plan->companies()->attach($company);
    }
    $openAll = $make('all');
    $openHidden = $make('hidden');
    $openSelected = $make('selected');

    $migration->up();

    $row = fn (RatePlan $plan) => DB::table('rate_plans')->find($plan->id);
    $link = fn (RatePlan $plan) => DB::table('company_rate_plans')->where('rate_plan_id', $plan->id)->first();

    expect($row($linkedSelected)->company_access)->toBe('selected')
        ->and($link($linkedSelected))->toMatchObject(['at_desk' => true, 'in_portal' => true])
        ->and($row($linkedAll)->company_access)->toBe('selected')
        ->and($link($linkedAll))->toMatchObject(['at_desk' => true, 'in_portal' => false])
        ->and($row($linkedHidden)->company_access)->toBe('selected')
        ->and($link($linkedHidden))->toMatchObject(['at_desk' => true, 'in_portal' => false])
        ->and($row($openAll))->toMatchObject(['company_access' => 'all', 'company_at_desk' => false, 'company_in_portal' => true])
        ->and($row($openHidden)->company_access)->toBe('everyone')
        ->and($row($openSelected)->company_access)->toBe('everyone');
});

test('the Companies tab shows the access, the options, the rows and the companies to add', function () {
    $setup = companyAccessSetup();
    $setup['derived']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $zeta = Company::factory()->for($setup['property'])->create(['name' => 'Zeta Travel']);
    $alpha = Company::factory()->for($setup['property'])->create(['name' => 'Alpha Corp']);
    $free = Company::factory()->for($setup['property'])->create(['name' => 'Free Co']);
    Company::factory()->suspended()->for($setup['property'])->create(['name' => 'Suspended Co']);
    $setup['derived']->companies()->attach($zeta, ['at_desk' => true, 'in_portal' => false]);
    $setup['derived']->companies()->attach($alpha, ['at_desk' => false, 'in_portal' => true]);

    $this->actingAs($setup['user'])
        ->get(route('settings.rate-plans.companies.edit', companyAccessParams($setup)))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/rate-plans/companies')
            ->where('access.companyAccess', 'selected')
            ->where('access.isDerived', true)
            ->where('access.companies.0.name', 'Alpha Corp')
            ->where('access.companies.0.atDesk', false)
            ->where('access.companies.0.inPortal', true)
            ->where('access.companies.0.login', null)
            ->where('access.companies.1.name', 'Zeta Travel')
            ->where('accessOptions', CompanyAccess::options())
            ->has('candidates', 1)
            ->where('candidates.0.id', $free->id)
            ->where('canManageCompanies', true)
            ->where('canViewCompanies', true));
});

test('a row shows the portal login status', function (Closure $arrange, ?string $label, bool $active) {
    $setup = companyAccessSetup();
    $setup['derived']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['derived']->companies()->attach($company);
    $arrange($company);

    $this->actingAs($setup['user'])
        ->get(route('settings.rate-plans.companies.edit', companyAccessParams($setup)))
        ->assertInertia(fn (Assert $page) => $page
            ->where($label === null ? 'access.companies.0.login' : 'access.companies.0.login.label', $label)
            ->where('access.companies.0.hasActiveLogin', $active));
})->with([
    'no login' => [fn () => null, null, false],
    'waiting' => [fn (Company $company) => CompanyUser::factory()->for($company)->pending()->create(), 'Waiting', false],
    'active' => [fn (Company $company) => CompanyUser::factory()->for($company)->create(), 'Active', true],
]);

test('the tab is a 404 for a Day use plan and its routes too', function () {
    $setup = companyAccessSetup();
    $setup['dayUse'] = RatePlan::factory()->for($setup['property'])->dayUse()->create();
    $company = Company::factory()->for($setup['property'])->create();

    $this->actingAs($setup['user']);
    $this->get(route('settings.rate-plans.companies.edit', companyAccessParams($setup, 'dayUse')))->assertNotFound();
    $this->put(route('settings.rate-plans.companies.update', companyAccessParams($setup, 'dayUse')), ['company_access' => 'all'])->assertNotFound();
    $this->post(route('settings.rate-plans.companies.store', companyAccessParams($setup, 'dayUse')), ['company_ids' => [$company->id]])->assertNotFound();
    expect($setup['dayUse']->refresh()->company_access)->toBe(CompanyAccess::Everyone);
});

test('settings.manage saves who can use the rate and logs it', function () {
    $setup = companyAccessSetup();
    $params = companyAccessParams($setup, 'basic');

    $this->actingAs($setup['user'])
        ->put(route('settings.rate-plans.companies.update', $params), ['company_access' => 'all', 'company_at_desk' => false, 'company_in_portal' => true])
        ->assertRedirect(route('settings.rate-plans.companies.edit', $params))
        ->assertInertiaFlash('toast.type', 'success');

    $log = Activity::query()->where('event', 'rate_plan.company_access_updated')->sole();
    $plan = $setup['basic']->refresh();

    expect($plan->company_access)->toBe(CompanyAccess::All)
        ->and($plan->company_at_desk)->toBeFalse()
        ->and($plan->company_in_portal)->toBeTrue()
        ->and($log->properties['from']['access'])->toBe('everyone')
        ->and($log->properties['to']['access'])->toBe('all')
        ->and($log->causer_id)->toBe($setup['user']->id);
});

test('saving the same access logs nothing', function () {
    $setup = companyAccessSetup();

    expect(app(SetRatePlanCompanyAccess::class)($setup['user'], $setup['basic'], CompanyAccess::Everyone))->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('All companies needs the front desk or the portal on', function () {
    $setup = companyAccessSetup();

    $this->actingAs($setup['user'])
        ->put(route('settings.rate-plans.companies.update', companyAccessParams($setup, 'basic')), ['company_access' => 'all', 'company_at_desk' => false, 'company_in_portal' => false])
        ->assertInertiaFlash('toast.type', 'error');

    expect($setup['basic']->refresh()->company_access)->toBe(CompanyAccess::Everyone)
        ->and(Activity::query()->count())->toBe(0);
});

test('Only these companies is for derived plans, and an archived plan cannot change', function () {
    $setup = companyAccessSetup();
    $action = app(SetRatePlanCompanyAccess::class);

    expect($action($setup['user'], $setup['basic'], CompanyAccess::Selected))->toBe(SetRatePlanCompanyAccess::REFUSAL_NOT_DERIVED)
        ->and($setup['basic']->refresh()->company_access)->toBe(CompanyAccess::Everyone);

    $setup['derived']->forceFill(['archived_at' => now()])->save();

    expect($action($setup['user'], $setup['derived'], CompanyAccess::All))->toBe(SetRatePlanCompanyAccess::REFUSAL_PLAN_CLOSED);
});

test('choosing Only these companies with nobody listed is allowed, and listed rows survive a switch away', function () {
    $setup = companyAccessSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $action = app(SetRatePlanCompanyAccess::class);

    expect($action($setup['user'], $setup['derived'], CompanyAccess::Selected))->toBeNull();

    app(AddRatePlanCompanies::class)($setup['user'], $setup['derived'], [$company->id]);
    $action($setup['user'], $setup['derived'], CompanyAccess::Everyone);

    expect($setup['derived']->companies()->count())->toBe(1);
});

test('adding companies lists them with both switches on and logs it', function () {
    $setup = companyAccessSetup();
    $setup['derived']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $companies = Company::factory()->count(2)->for($setup['property'])->create();

    $this->actingAs($setup['user'])
        ->post(route('settings.rate-plans.companies.store', companyAccessParams($setup)), ['company_ids' => $companies->pluck('id')->all()])
        ->assertRedirect(route('settings.rate-plans.companies.edit', companyAccessParams($setup)))
        ->assertInertiaFlash('toast.type', 'success');

    $rows = DB::table('company_rate_plans')->where('rate_plan_id', $setup['derived']->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($row) => $row->at_desk && $row->in_portal))->toBeTrue()
        ->and(Activity::query()->where('event', 'rate_plan.companies_added')->sole()->properties['company_ids'])->toBe($companies->pluck('id')->all());

    app(AddRatePlanCompanies::class)($setup['user'], $setup['derived'], [$companies[0]->id]);

    expect(Activity::query()->where('event', 'rate_plan.companies_added')->count())->toBe(1);
});

test('adding is refused for the wrong plan or company and writes nothing', function (Closure $make, string $refusal) {
    $setup = companyAccessSetup();
    $setup['derived']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $company = $make($setup);
    $plan = $company instanceof RatePlan ? $company : $setup['derived'];
    $ids = $company instanceof Company ? [$company->id] : [Company::factory()->for($setup['property'])->create()->id];

    expect(app(AddRatePlanCompanies::class)($setup['user'], $plan, $ids))->toBe($refusal)
        ->and(DB::table('company_rate_plans')->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'suspended company' => [fn ($setup) => Company::factory()->suspended()->for($setup['property'])->create(), AddRatePlanCompanies::REFUSAL_COMPANY_UNAVAILABLE],
    'foreign company' => [fn ($setup) => Company::factory()->create(), AddRatePlanCompanies::REFUSAL_COMPANY_UNAVAILABLE],
    'plan not Only these companies' => [fn ($setup) => $setup['basic'], AddRatePlanCompanies::REFUSAL_NOT_SELECTED],
]);

test('a switch saves at once, and turning both off is refused', function () {
    $setup = companyAccessSetup();
    $setup['derived']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['derived']->companies()->attach($company, ['at_desk' => true, 'in_portal' => true]);
    $route = route('settings.rate-plans.companies.rows.update', companyAccessParams($setup, 'derived', $company));

    $this->actingAs($setup['user'])->put($route, ['at_desk' => false, 'in_portal' => true])->assertInertiaFlash('toast.type', 'success');

    expect(DB::table('company_rate_plans')->first())->toMatchObject(['at_desk' => false, 'in_portal' => true])
        ->and(Activity::query()->where('event', 'rate_plan.company_updated')->count())->toBe(1);

    $this->put($route, ['at_desk' => false, 'in_portal' => false])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Turn on at least one, or remove the company.']);

    expect(DB::table('company_rate_plans')->first())->toMatchObject(['at_desk' => false, 'in_portal' => true])
        ->and(Activity::query()->count())->toBe(1);

    app(UpdateRatePlanCompany::class)($setup['user'], $setup['derived'], $company, false, true);

    expect(Activity::query()->count())->toBe(1);
});

test('a company that is not listed is a 404 on its row routes', function () {
    $setup = companyAccessSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $route = companyAccessParams($setup, 'derived', $company);

    $this->actingAs($setup['user']);
    $this->put(route('settings.rate-plans.companies.rows.update', $route), ['at_desk' => true, 'in_portal' => true])->assertNotFound();
    $this->delete(route('settings.rate-plans.companies.destroy', $route))->assertNotFound();
});

test('removing the last company is allowed and logged', function () {
    $setup = companyAccessSetup();
    $setup['derived']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['derived']->companies()->attach($company);

    $this->actingAs($setup['user'])
        ->delete(route('settings.rate-plans.companies.destroy', companyAccessParams($setup, 'derived', $company)))
        ->assertRedirect(route('settings.rate-plans.companies.edit', companyAccessParams($setup)))
        ->assertInertiaFlash('toast.type', 'success');

    expect($setup['derived']->companies()->count())->toBe(0)
        ->and($setup['derived']->refresh()->company_access)->toBe(CompanyAccess::Selected)
        ->and(Activity::query()->where('event', 'rate_plan.company_removed')->sole()->properties['company_id'])->toBe($company->id)
        ->and(app(RemoveRatePlanCompany::class)($setup['user'], $setup['derived'], $company))->toBe(RemoveRatePlanCompany::REFUSAL_NOT_LISTED);
});

test('settings.manage opens the tab; adding and removing also need company.manage', function () {
    $setup = companyAccessSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['derived']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $setup['derived']->companies()->attach($company);
    $manager = User::factory()->withPermissions(['settings.manage'])->for($setup['property'])->create();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $params = companyAccessParams($setup, 'derived', $company);

    $this->actingAs($frontDesk)->get(route('settings.rate-plans.companies.edit', $params))->assertForbidden();
    $this->put(route('settings.rate-plans.companies.update', $params), ['company_access' => 'all'])->assertForbidden();

    $this->actingAs($manager)->get(route('settings.rate-plans.companies.edit', $params))
        ->assertInertia(fn (Assert $page) => $page->where('canManageCompanies', false)->where('canViewCompanies', false)->where('candidates', []));
    $this->put(route('settings.rate-plans.companies.rows.update', $params), ['at_desk' => true, 'in_portal' => false])->assertRedirect();
    $this->post(route('settings.rate-plans.companies.store', $params), ['company_ids' => [$company->id]])->assertForbidden();
    $this->delete(route('settings.rate-plans.companies.destroy', $params))->assertForbidden();

    expect($setup['derived']->companies()->count())->toBe(1);
});

test("another property's plan is a 404", function () {
    $setup = companyAccessSetup();
    $foreign = RatePlan::factory()->create();

    $this->actingAs($setup['user'])->get(route('settings.rate-plans.companies.edit', ['property' => $setup['property'], 'ratePlan' => $foreign]))->assertNotFound();
});
