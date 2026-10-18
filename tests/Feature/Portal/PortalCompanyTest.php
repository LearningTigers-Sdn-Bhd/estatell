<?php

use App\Actions\CityLedger\UpdatePortalCompanyDetails;
use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use App\Enums\DefaultRoleEnum;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PortalSetup.php';

beforeEach(function () {
    $this->user = portalUser();
    $this->property = Property::factory()->create();
    [$this->company, $this->link] = portalLinkFor($this->user, $this->property);
    $this->companyUrl = fn (?Property $property = null) => route('portal.company', ['property' => ($property ?? $this->property)->code]);
});

/**
 * The form fields the company sends, from the company as it is now, with overrides.
 *
 * @return array<string, mixed>
 */
function portalCompanyPayload(Company $company, array $overrides = []): array
{
    $current = collect(UpdatePortalCompanyDetails::FIELDS)
        ->mapWithKeys(fn (string $field): array => [$field => $company->refresh()->{$field}])
        ->all();

    return array_merge($current, $overrides);
}

test('the company page shows the company\'s own fields and no credit terms', function () {
    $this->company->forceFill(['brn' => '202001012345', 'contact_email' => 'booking@borneotravel.my', 'billing_city' => 'Kota Kinabalu', 'billing_country_code' => 'MY'])->save();

    $this->actingAs($this->user)
        ->get(($this->companyUrl)())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/company')
            ->where('company.name', 'Borneo Travel')
            ->where('company.typeLabel', CompanyType::Corporate->label())
            ->where('company.status.value', 'active')
            ->where('company.profileManagedByCompany', false)
            ->where('company.contactEmail', 'booking@borneotravel.my')
            ->where('company.brn', '202001012345')
            ->where('company.billingCountryCode', 'MY')
            ->has('countries')
            ->missing('company.creditLimit')
            ->missing('company.paymentTermsDays')
        );
});

test('saving the details updates the company and logs company.updated with the company user as causer', function () {
    $this->actingAs($this->user)
        ->put(($this->companyUrl)(), [
            'profile_managed_by_company' => true,
            'contact_email' => 'Accounts@Borneo.my',
            'contact_phone' => '+60 12 345 6789',
            'brn' => '202001012345',
            'tin' => 'C12345678901',
            'sst_registration_number' => 'W10-1808-32000123',
            'billing_address_line1' => 'Lot 5, Jalan Pantai',
            'billing_address_line2' => '',
            'billing_city' => 'Kota Kinabalu',
            'billing_state_code' => 'Sabah',
            'billing_postal_code' => '88000',
            'billing_country_code' => 'MY',
        ])
        ->assertRedirect(($this->companyUrl)())
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Company details saved.']);

    expect($this->company->refresh())
        ->profile_managed_by_company->toBeTrue()
        ->contact_email->toBe('accounts@borneo.my')
        ->contact_phone->toBe('+60123456789')
        ->brn->toBe('202001012345')
        ->billing_address_line2->toBeNull()
        ->billing_country_code->toBe('MY')
        ->name->toBe('Borneo Travel');

    $activity = Activity::query()->where('event', 'company.updated')->sole();

    expect($activity->causer->is($this->user))->toBeTrue()
        ->and($activity->properties['old']['profile_managed_by_company'])->toBeFalse()
        ->and($activity->properties['attributes']['profile_managed_by_company'])->toBeTrue()
        ->and($activity->properties['property_id'])->toBe($this->property->id);
});

test('saving unchanged details logs nothing', function () {
    $this->actingAs($this->user)
        ->put(($this->companyUrl)(), portalCompanyPayload($this->company))
        ->assertRedirect(($this->companyUrl)());

    expect(Activity::query()->where('event', 'company.updated')->count())->toBe(0);
});

test('ticking the box makes the staff billing form refuse, and unticking lets staff edit again', function () {
    $staff = User::factory()->withRole(DefaultRoleEnum::Finance)->create();
    $staffProperty = $staff->property;
    [$portalCompany] = portalLinkFor($this->user, $staffProperty);
    $billingUrl = route('finance.city-ledger.companies.billing.update', ['property' => $staffProperty->code, 'company' => $portalCompany->id]);
    $portalUrl = route('portal.company.update', ['property' => $staffProperty->code]);

    $this->actingAs($this->user)
        ->put($portalUrl, portalCompanyPayload($portalCompany, ['profile_managed_by_company' => true]))
        ->assertRedirect();

    $this->actingAs($staff)
        ->put($billingUrl, ['brn' => 'STAFF-EDIT'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The company manages these details in the company portal.']);

    expect($portalCompany->refresh()->brn)->not->toBe('STAFF-EDIT');

    $this->actingAs($this->user)
        ->put($portalUrl, portalCompanyPayload($portalCompany, ['profile_managed_by_company' => false]))
        ->assertRedirect();

    $this->actingAs($staff)
        ->put($billingUrl, ['brn' => 'STAFF-EDIT', 'billing_country_code' => 'MY'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Tax numbers and billing address saved.']);

    expect($portalCompany->refresh()->brn)->toBe('STAFF-EDIT');
});

test('an invalid email, phone or country is refused', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->put(($this->companyUrl)(), portalCompanyPayload($this->company, $overrides))
        ->assertSessionHasErrors($field);

    expect(Activity::query()->where('event', 'company.updated')->count())->toBe(0);
})->with([
    'email' => [['contact_email' => 'not-an-email'], 'contact_email'],
    'phone' => [['contact_phone' => '12'], 'contact_phone'],
    'country' => [['billing_country_code' => 'XX'], 'billing_country_code'],
]);

test('the name, type, status and any other key are ignored', function () {
    $otherProperty = Property::factory()->create();

    $this->actingAs($this->user)
        ->put(($this->companyUrl)(), portalCompanyPayload($this->company, [
            'name' => 'Hijacked',
            'type' => CompanyType::Government->value,
            'status' => CompanyStatus::Suspended->value,
            'property_id' => $otherProperty->id,
            'company_id' => 999,
            'credit_limit' => '999999.00',
            'brn' => '202001012345',
        ]))
        ->assertRedirect();

    expect($this->company->refresh())
        ->name->toBe('Borneo Travel')
        ->type->toBe(CompanyType::Corporate)
        ->status->toBe(CompanyStatus::Active)
        ->property_id->toBe($this->property->id)
        ->credit_limit->toBeNull()
        ->brn->toBe('202001012345');
});

test('a company user cannot reach another property\'s company page', function () {
    $other = Property::factory()->create();

    $this->actingAs($this->user)->get(($this->companyUrl)($other))->assertNotFound();
    $this->actingAs($this->user)->put(($this->companyUrl)($other), portalCompanyPayload($this->company))->assertNotFound();
});

test('staff, a pending link and a revoked link do not reach the company page', function (string $case) {
    $actor = match ($case) {
        'staff' => User::factory()->withRole(DefaultRoleEnum::Owner)->for($this->property)->create(),
        default => $this->user,
    };

    match ($case) {
        'pending link' => $this->link->forceFill(['status' => 'pending', 'accepted_at' => null])->save(),
        'revoked link' => $this->link->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save(),
        default => null,
    };

    $this->actingAs($actor)->get(($this->companyUrl)())->assertNotFound();
    $this->actingAs($actor)->put(($this->companyUrl)(), portalCompanyPayload($this->company))->assertNotFound();
})->with(['staff', 'pending link', 'revoked link']);

test('a Suspended company can still save its details', function () {
    $this->company->forceFill(['status' => CompanyStatus::Suspended])->save();

    $this->actingAs($this->user)
        ->put(($this->companyUrl)(), portalCompanyPayload($this->company, ['contact_email' => 'new@borneo.my']))
        ->assertRedirect(($this->companyUrl)());

    expect($this->company->refresh()->contact_email)->toBe('new@borneo.my');
});
