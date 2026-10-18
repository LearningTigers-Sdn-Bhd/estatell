<?php

use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use App\Enums\DefaultRoleEnum;
use App\Models\Company;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{0: User, 1: Property}
 */
function companyStaff(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    $user = User::factory()->withRole($role)->create();

    return [$user, $user->property];
}

function companiesUrl(Property $property, string $suffix = ''): string
{
    return "/p/{$property->code}/finance/city-ledger/companies{$suffix}";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Borneo Travel',
        'type' => 'travel_agent',
        'brn' => '202001012345',
        'tin' => null,
        'sst_registration_number' => null,
        'contact_email' => ' Accounts@BorneoTravel.my ',
        'contact_phone' => '012-345 6789',
        'credit_limit' => '20000.00',
        'payment_terms_days' => 30,
        'direct_bill_enabled' => true,
    ], $overrides);
}

test('company types and statuses expose their labels and options', function () {
    expect(CompanyType::options())->toBe([
        ['value' => 'corporate', 'label' => 'Corporate'],
        ['value' => 'travel_agent', 'label' => 'Travel agent'],
        ['value' => 'government', 'label' => 'Government'],
    ])
        ->and(CompanyStatus::Active->canTransitionTo(CompanyStatus::Suspended))->toBeTrue()
        ->and(CompanyStatus::Active->canTransitionTo(CompanyStatus::Active))->toBeFalse();
});

test('company.view roles can open the list and only company.manage roles can add', function (DefaultRoleEnum $role, int $listStatus, ?bool $canManage, int $storeStatus) {
    [$user, $property] = companyStaff($role);

    $list = $this->actingAs($user)->get(companiesUrl($property))->assertStatus($listStatus);

    if ($canManage !== null) {
        $list->assertInertia(fn (Assert $page) => $page->where('canManage', $canManage));
    }

    $this->actingAs($user)->post(companiesUrl($property), ['name' => 'JKR Sabah', 'type' => 'government'])->assertStatus($storeStatus);
})->with([
    [DefaultRoleEnum::Owner, 200, true, 302],
    [DefaultRoleEnum::Manager, 200, true, 302],
    [DefaultRoleEnum::Finance, 200, true, 302],
    [DefaultRoleEnum::FrontDesk, 200, false, 403],
    [DefaultRoleEnum::Housekeeping, 403, null, 403],
]);

test('the list shows every company of the property in name order', function () {
    [$user, $property] = companyStaff(DefaultRoleEnum::FrontDesk);
    Company::factory()->for($property)->create(['name' => 'petronas Miri']);
    Company::factory()->for($property)->travelAgent()->suspended()->create(['name' => 'Borneo Travel', 'credit_limit' => '5000.00']);
    Company::factory()->create(['name' => 'Another property']);

    $this->actingAs($user)
        ->get(companiesUrl($property))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/city-ledger/companies/index')
            ->has('companies', 2)
            ->where('companies.0.name', 'Borneo Travel')
            ->where('companies.0.typeLabel', 'Travel agent')
            ->where('companies.0.creditLimit', '5000.00')
            ->where('companies.0.status.value', 'suspended')
            ->where('companies.1.name', 'petronas Miri')
            ->where('canManage', false)
        );
});

test('another property\'s company returns 404', function () {
    [$user] = companyStaff();
    [, $otherProperty] = companyStaff();
    $company = Company::factory()->for($otherProperty)->create();

    $this->actingAs($user)->get(companiesUrl($user->property, "/{$company->id}"))->assertNotFound();
    $this->actingAs($user)->post(companiesUrl($user->property, "/{$company->id}/suspend"))->assertNotFound();
});

test('adding a company stores the contact and credit fields as an active company and logs it', function () {
    [$user, $property] = companyStaff(DefaultRoleEnum::Finance);

    $response = $this->actingAs($user)->post(companiesUrl($property), companyPayload());

    $company = $property->companies()->sole();
    $response->assertRedirect(companiesUrl($property, "/{$company->id}"))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Borneo Travel added.']);

    expect($company->type)->toBe(CompanyType::TravelAgent)
        ->and($company->status)->toBe(CompanyStatus::Active)
        ->and($company->contact_email)->toBe('accounts@borneotravel.my')
        ->and($company->contact_phone)->toBe('+60123456789')
        ->and($company->brn)->toBeNull()
        ->and($company->credit_limit)->toBe('20000.00')
        ->and($company->payment_terms_days)->toBe(30)
        ->and($company->direct_bill_enabled)->toBeTrue()
        ->and(Activity::query()->where('event', 'company.created')->sole()->causer_id)->toBe($user->id);
});

test('the add sheet needs only name and type', function () {
    [$user, $property] = companyStaff();

    $this->actingAs($user)
        ->post(companiesUrl($property), ['name' => 'JKR Sabah', 'type' => 'government', 'contact_email' => '', 'contact_phone' => ''])
        ->assertSessionHasNoErrors();

    expect($property->companies()->sole())
        ->contact_email->toBeNull()
        ->credit_limit->toBeNull()
        ->payment_terms_days->toBeNull()
        ->direct_bill_enabled->toBeFalse();
});

test('the add sheet and details form never touch the tax numbers or billing address', function () {
    [$user, $property] = companyStaff();
    $company = Company::factory()->for($property)->create(['brn' => '202001012345', 'billing_city' => 'Kota Kinabalu']);

    $this->actingAs($user)
        ->post(companiesUrl($property), companyPayload(['tin' => 'C1', 'billing_city' => 'Sandakan']))
        ->assertSessionHasNoErrors();
    $this->actingAs($user)
        ->put(companiesUrl($property, "/{$company->id}/details"), companyPayload(['name' => 'Kept', 'brn' => 'X', 'billing_city' => 'Tawau']))
        ->assertSessionHasNoErrors();

    expect($property->companies()->where('name', 'Borneo Travel')->sole())
        ->tin->toBeNull()
        ->billing_city->toBeNull()
        ->and($company->refresh())
        ->brn->toBe('202001012345')
        ->billing_city->toBe('Kota Kinabalu');
});

test('hotel staff save the tax numbers and billing address and the page shows them', function () {
    [$user, $property] = companyStaff(DefaultRoleEnum::Finance);
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel']);

    $this->actingAs($user)
        ->put(companiesUrl($property, "/{$company->id}/billing"), [
            'brn' => '202001012345',
            'tin' => '',
            'billing_address_line1' => 'Lot 5, Jalan Pantai',
            'billing_postal_code' => '88000',
            'billing_city' => 'Kota Kinabalu',
            'billing_state_code' => 'Sabah',
            'billing_country_code' => 'MY',
            'name' => 'Ignored',
        ])
        ->assertRedirect(companiesUrl($property, "/{$company->id}"))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Tax numbers and billing address saved.']);

    expect($company->refresh())
        ->brn->toBe('202001012345')
        ->tin->toBeNull()
        ->name->toBe('Borneo Travel')
        ->and(Activity::query()->where('event', 'company.updated')->count())->toBe(1);

    $this->actingAs($user)
        ->get(companiesUrl($property, "/{$company->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.billingCountryCode', 'MY')
            ->where('company.billingAddress', "Lot 5, Jalan Pantai\n88000 Kota Kinabalu\nSabah, Malaysia")
            ->where('company.profileManagedByCompany', false)
            ->has('countries')
        );
});

test('the billing form is refused once the company manages its own details', function () {
    [$user, $property] = companyStaff();
    $company = Company::factory()->for($property)->managedByCompany()->create(['brn' => '202001012345']);

    $this->actingAs($user)
        ->put(companiesUrl($property, "/{$company->id}/billing"), ['brn' => 'X'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The company manages these details in the company portal.']);

    expect($company->refresh()->brn)->toBe('202001012345')
        ->and(Activity::query()->where('event', 'company.updated')->count())->toBe(0);
});

test('the billing form rejects an unknown country and front desk cannot save it', function () {
    [$user, $property] = companyStaff();
    $company = Company::factory()->for($property)->create();

    $this->actingAs($user)
        ->put(companiesUrl($property, "/{$company->id}/billing"), ['billing_country_code' => 'XX'])
        ->assertSessionHasErrors('billing_country_code');

    [$frontDesk] = companyStaff(DefaultRoleEnum::FrontDesk);
    $other = Company::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)
        ->put(companiesUrl($frontDesk->property, "/{$other->id}/billing"), ['brn' => 'X'])
        ->assertForbidden();
});

test('the add sheet rejects invalid input', function (array $overrides, string $field) {
    [$user, $property] = companyStaff();
    Company::factory()->for($property)->create(['name' => 'Borneo Travel']);

    $this->actingAs($user)
        ->post(companiesUrl($property), companyPayload($overrides))
        ->assertSessionHasErrors($field);

    expect($property->companies()->count())->toBe(1);
})->with([
    'a blank name' => [['name' => '   '], 'name'],
    'a name used in another case' => [['name' => 'BORNEO TRAVEL'], 'name'],
    'an unknown type' => [['type' => 'agency'], 'type'],
    'a bad phone number' => [['contact_phone' => '12'], 'contact_phone'],
    'a bad email' => [['contact_email' => 'not-an-email'], 'contact_email'],
    'a negative credit limit' => [['credit_limit' => '-1'], 'credit_limit'],
    'terms over a year' => [['payment_terms_days' => 366], 'payment_terms_days'],
]);

test('the credit tab rejects invalid input', function (array $payload, string $field) {
    [$user, $property] = companyStaff();
    $company = Company::factory()->for($property)->create(['credit_limit' => '100.00']);

    $this->actingAs($user)
        ->put(companiesUrl($property, "/{$company->id}/credit"), $payload)
        ->assertSessionHasErrors($field);

    expect($company->refresh()->credit_limit)->toBe('100.00');
})->with([
    'a negative credit limit' => [['credit_limit' => '-1'], 'credit_limit'],
    'a credit limit with three decimals' => [['credit_limit' => '10.123'], 'credit_limit'],
    'terms over a year' => [['payment_terms_days' => 366], 'payment_terms_days'],
]);

test('a name used by another property is allowed', function () {
    [$user, $property] = companyStaff();
    Company::factory()->create(['name' => 'Borneo Travel']);

    $this->actingAs($user)->post(companiesUrl($property), companyPayload())->assertSessionHasNoErrors();

    expect($property->companies()->count())->toBe(1);
});

test('the details tab saves only its fields and logs the old and new values', function () {
    [$user, $property] = companyStaff();
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel', 'payment_terms_days' => 30]);

    $this->actingAs($user)
        ->put(companiesUrl($property, "/{$company->id}/details"), companyPayload(['name' => 'borneo travel', 'contact_email' => '', 'payment_terms_days' => 45]))
        ->assertRedirect(companiesUrl($property, "/{$company->id}"))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'borneo travel saved.']);

    $activity = Activity::query()->where('event', 'company.updated')->sole();

    expect($company->refresh()->name)->toBe('borneo travel')
        ->and($company->contact_email)->toBeNull()
        ->and($company->payment_terms_days)->toBe(30)
        ->and($activity->properties['old']['name'])->toBe('Borneo Travel')
        ->and($activity->properties['attributes']['name'])->toBe('borneo travel');
});

test('the credit tab saves the limit, terms and how the company pays', function (string $choice, bool $directBill) {
    [$user, $property] = companyStaff(DefaultRoleEnum::Finance);
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel', 'credit_limit' => '100.00', 'payment_terms_days' => 30, 'direct_bill_enabled' => ! $directBill]);

    $this->actingAs($user)
        ->put(companiesUrl($property, "/{$company->id}/credit"), ['direct_bill_enabled' => $choice, 'credit_limit' => '', 'payment_terms_days' => '45'])
        ->assertRedirect(companiesUrl($property, "/{$company->id}/credit"))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Borneo Travel saved.']);

    expect($company->refresh()->credit_limit)->toBeNull()
        ->and($company->payment_terms_days)->toBe(45)
        ->and($company->direct_bill_enabled)->toBe($directBill)
        ->and($company->name)->toBe('Borneo Travel');
})->with([
    'pay at check-out' => ['0', false],
    'direct billing' => ['1', true],
]);

test('each tab has its own URL and sends only its options', function () {
    [$user, $property] = companyStaff(DefaultRoleEnum::FrontDesk);
    $company = Company::factory()->for($property)->create();

    $this->actingAs($user)
        ->get(companiesUrl($property, "/{$company->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/city-ledger/companies/show')
            ->where('tab', 'details')
            ->has('types', 3)
            ->where('canManage', false)
        );

    $this->actingAs($user)
        ->get(companiesUrl($property, "/{$company->id}/credit"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'credit')
            ->missing('types')
        );

    $this->actingAs($user)->put(companiesUrl($property, "/{$company->id}/nope"), [])->assertNotFound();
});

test('saving a company without changes logs nothing', function () {
    [$user, $property] = companyStaff();
    $this->actingAs($user)->post(companiesUrl($property), companyPayload());
    $company = $property->companies()->sole();

    $this->actingAs($user)->put(companiesUrl($property, "/{$company->id}/details"), companyPayload())->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'company.updated')->count())->toBe(0);
});

test('a company can be suspended and reactivated once each', function () {
    [$user, $property] = companyStaff(DefaultRoleEnum::Manager);
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel']);

    $this->actingAs($user)->post(companiesUrl($property, "/{$company->id}/suspend"))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Borneo Travel suspended.']);
    expect($company->refresh()->status)->toBe(CompanyStatus::Suspended);

    $this->actingAs($user)->post(companiesUrl($property, "/{$company->id}/suspend"))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This company is already suspended.']);

    $this->actingAs($user)->post(companiesUrl($property, "/{$company->id}/reactivate"))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Borneo Travel reactivated.']);
    expect($company->refresh()->status)->toBe(CompanyStatus::Active);

    $this->actingAs($user)->post(companiesUrl($property, "/{$company->id}/reactivate"))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This company is already active.']);

    expect(Activity::query()->where('event', 'company.suspended')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'company.reactivated')->count())->toBe(1);
});

test('front desk can open a company but cannot edit, suspend or reactivate it', function () {
    [$user, $property] = companyStaff(DefaultRoleEnum::FrontDesk);
    $company = Company::factory()->for($property)->create();

    $this->actingAs($user)->get(companiesUrl($property, "/{$company->id}"))->assertOk();
    $this->actingAs($user)->get(companiesUrl($property, "/{$company->id}/credit"))->assertOk();
    $this->actingAs($user)->put(companiesUrl($property, "/{$company->id}/details"), companyPayload())->assertForbidden();
    $this->actingAs($user)->put(companiesUrl($property, "/{$company->id}/credit"), [])->assertForbidden();
    $this->actingAs($user)->post(companiesUrl($property, "/{$company->id}/suspend"))->assertForbidden();
    $this->actingAs($user)->post(companiesUrl($property, "/{$company->id}/reactivate"))->assertForbidden();
});

test('the database keeps company names unique per property and protects referenced companies', function () {
    $company = Company::factory()->create(['name' => 'Borneo Travel']);

    expect(fn () => DB::transaction(fn () => Company::factory()->for($company->property)->create(['name' => 'BORNEO travel'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => Reservation::factory()->create(['company_id' => $company->id + 1000])))
        ->toThrow(QueryException::class);

    Reservation::factory()->for($company->property)->create(['company_id' => $company->id]);

    expect(fn () => DB::transaction(fn () => $company->delete()))->toThrow(QueryException::class);
});

test('staff with company.view get the finance context with companies', function () {
    [$user, $property] = companyStaff(DefaultRoleEnum::FrontDesk);

    $this->actingAs($user)
        ->get(companiesUrl($property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'finance')
            ->where('navigation.contexts.1.key', 'finance')
            ->where('navigation.contexts.1.groups.0.items.0.title', 'Companies')
            ->where('navigation.contexts.1.groups.0.items.0.active', true)
        );
});
