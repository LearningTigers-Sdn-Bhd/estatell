<?php

use App\Actions\Onboarding\SkipOnboardingStep;
use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use App\Enums\OnboardingStep;
use App\Enums\PaymentMethodType;
use App\Enums\PropertyType;
use App\Enums\StepState;
use App\Models\Company;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Checks\CompaniesCheck;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->save = fn (array $rows) => $this->actingAs($this->owner)
        ->put(route('onboarding.companies.update', $this->property->code), ['rows' => $rows]);
    $this->cityLedger = fn () => $this->property->paymentMethods()->where('type', PaymentMethodType::DirectBill)->firstOrFail();
});

function companyRow(string $name, array $overrides = []): array
{
    return [
        'id' => null,
        'name' => $name,
        'type' => 'corporate',
        'contact_email' => 'travel@petronas.my',
        'contact_phone' => null,
        'credit_limit' => '20000',
        'payment_terms_days' => 30,
        'direct_bill_enabled' => true,
        'brn' => null,
        'tin' => null,
        'sst_registration_number' => null,
        ...$overrides,
    ];
}

function savedRow(Company $company, array $overrides = []): array
{
    return companyRow($company->name, [
        'id' => $company->id,
        'type' => $company->type->value,
        'contact_email' => $company->contact_email,
        'credit_limit' => $company->credit_limit,
        'payment_terms_days' => $company->payment_terms_days,
        'direct_bill_enabled' => $company->direct_bill_enabled,
        ...$overrides,
    ]);
}

test('the check is done with an active company and not started without one', function () {
    $check = new CompaniesCheck;

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    Company::factory()->for($this->property)->suspended()->create();

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    Company::factory()->for($this->property)->create();

    expect($check->state($this->property))->toBe(StepState::Done);
});

test('the page lists saved companies with the suspended ones read-only', function () {
    Company::factory()->for($this->property)->create(['name' => 'Borneo Tours']);
    Company::factory()->for($this->property)->suspended()->create(['name' => 'Old Co']);

    $this->actingAs($this->owner)
        ->get(route('onboarding.companies.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/companies')
            ->has('companies', 2)
            ->where('companies.0.name', 'Borneo Tours')
            ->where('companies.0.isEditable', true)
            ->where('companies.1.isEditable', false)
            ->has('types', 3)
            ->where('currency.code', 'MYR')
            ->where('locked', false));
});

test('saving adds a company with the fields of the row and turns City ledger on', function () {
    expect(($this->cityLedger)()->active)->toBeFalse();

    ($this->save)([companyRow('Petronas Carigali', ['contact_phone' => '012-345 6789', 'brn' => '201901234567'])])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('onboarding.go-live.show', $this->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Companies saved. City ledger turned on in Payment methods.']);

    $company = $this->property->companies()->sole();

    expect($company->status)->toBe(CompanyStatus::Active)
        ->and($company->type)->toBe(CompanyType::Corporate)
        ->and($company->credit_limit)->toBe('20000.00')
        ->and($company->payment_terms_days)->toBe(30)
        ->and($company->contact_phone)->toBe('+60123456789')
        ->and($company->brn)->toBe('201901234567')
        ->and($company->direct_bill_enabled)->toBeTrue()
        ->and(($this->cityLedger)()->active)->toBeTrue()
        ->and(($this->cityLedger)()->transactionCode->active)->toBeTrue();
});

test('a company without direct bill leaves City ledger off', function () {
    ($this->save)([companyRow('Borneo Tours', ['direct_bill_enabled' => false])])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Companies saved.']);

    expect(($this->cityLedger)()->active)->toBeFalse();
});

test('an empty credit limit and empty terms save as null', function () {
    ($this->save)([companyRow('Borneo Tours', ['credit_limit' => '', 'payment_terms_days' => '', 'contact_email' => ''])])
        ->assertSessionHasNoErrors();

    $company = $this->property->companies()->sole();

    expect($company->credit_limit)->toBeNull()
        ->and($company->payment_terms_days)->toBeNull()
        ->and($company->contact_email)->toBeNull();
});

test('saving updates a saved company and logs the change', function () {
    $company = Company::factory()->for($this->property)->create(['name' => 'Borneo Tours', 'credit_limit' => null]);

    ($this->save)([savedRow($company, ['name' => 'Borneo Tours Sdn Bhd', 'credit_limit' => '5000', 'tin' => 'C2584563200'])])
        ->assertSessionHasNoErrors();

    $company->refresh();

    expect($company->name)->toBe('Borneo Tours Sdn Bhd')
        ->and($company->credit_limit)->toBe('5000.00')
        ->and($company->tin)->toBe('C2584563200')
        ->and($this->property->companies()->count())->toBe(1);
});

test('a company that manages its own profile keeps its tax numbers', function () {
    $company = Company::factory()->for($this->property)->managedByCompany()->create(['brn' => 'OLD-BRN', 'tin' => 'OLD-TIN']);

    ($this->save)([savedRow($company, ['brn' => 'NEW-BRN', 'tin' => 'NEW-TIN', 'contact_phone' => '012-345 6789'])])
        ->assertSessionHasNoErrors();

    $company->refresh();

    expect($company->brn)->toBe('OLD-BRN')
        ->and($company->tin)->toBe('OLD-TIN')
        ->and($company->contact_phone)->toBe('+60123456789');
});

test('a saved company without a row is left alone', function () {
    $company = Company::factory()->for($this->property)->create();

    ($this->save)([companyRow('Borneo Tours')])->assertSessionHasNoErrors();

    expect($company->refresh()->status)->toBe(CompanyStatus::Active)
        ->and($this->property->companies()->count())->toBe(2);
});

test('a repeated save changes nothing', function () {
    ($this->save)([companyRow('Borneo Tours')])->assertSessionHasNoErrors();
    $company = $this->property->companies()->sole();

    ($this->save)([savedRow($company)])->assertSessionHasNoErrors();

    expect($this->property->companies()->count())->toBe(1)
        ->and($company->refresh()->updated_at->equalTo($company->updated_at))->toBeTrue();
});

test('names must be unique in the table and against suspended companies, ignoring case', function () {
    Company::factory()->for($this->property)->suspended()->create(['name' => 'Old Co']);

    ($this->save)([companyRow('Borneo Tours'), companyRow('borneo tours')])->assertSessionHasErrors('rows.1.name');
    ($this->save)([companyRow('OLD CO')])->assertSessionHasErrors('rows.0.name');
    ($this->save)([companyRow('')])->assertSessionHasErrors('rows.0.name');

    expect($this->property->companies()->count())->toBe(1);
});

test('row fields keep the bounds of the company rules', function () {
    ($this->save)([companyRow('A', ['type' => 'airline'])])->assertSessionHasErrors('rows.0.type');
    ($this->save)([companyRow('A', ['contact_email' => 'not-an-email'])])->assertSessionHasErrors('rows.0.contact_email');
    ($this->save)([companyRow('A', ['credit_limit' => '-1'])])->assertSessionHasErrors('rows.0.credit_limit');
    ($this->save)([companyRow('A', ['payment_terms_days' => 400])])->assertSessionHasErrors('rows.0.payment_terms_days');

    expect($this->property->companies()->count())->toBe(0);
});

test('a row cannot point at a suspended company or another property company', function () {
    $suspended = Company::factory()->for($this->property)->suspended()->create();
    $other = Company::factory()->create();

    ($this->save)([savedRow($suspended, ['name' => 'Hacked'])])->assertSessionHasErrors('rows.0.id');
    ($this->save)([companyRow('Hacked', ['id' => $other->id])])->assertSessionHasErrors('rows.0.id');

    expect($suspended->refresh()->name)->not->toBe('Hacked')
        ->and($other->refresh()->name)->not->toBe('Hacked');
});

test('saving no rows is valid and keeps a skip', function () {
    app(SkipOnboardingStep::class)($this->property, OnboardingStep::Companies, $this->owner);

    ($this->save)([])->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::Companies)->count())->toBe(1)
        ->and(app(OnboardingProgress::class)->state($this->property, OnboardingStep::Companies))->toBe(StepState::Done);
});

test('saving a company removes the skip', function () {
    app(SkipOnboardingStep::class)($this->property, OnboardingStep::Companies, $this->owner);

    ($this->save)([companyRow('Borneo Tours')])->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::Companies)->exists())->toBeFalse();
});

test('the step is locked until Basics is done', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->put(route('onboarding.companies.update', $property->code), ['rows' => [companyRow('Borneo Tours')]])
        ->assertSessionHasErrors('onboarding');

    expect($property->companies()->count())->toBe(0);
});

test('import reads a file into rows without saving', function () {
    $file = UploadedFile::fake()->createWithContent('companies.csv', "name,type,email,direct_billing\nBorneo Tours,travel agent,ops@borneotours.my,no\n");

    $this->actingAs($this->owner)
        ->post(route('onboarding.companies.import', $this->property->code), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('rows.0.name', 'Borneo Tours')
        ->assertJsonPath('rows.0.type', 'travel_agent')
        ->assertJsonPath('rows.0.direct_bill_enabled', false);

    expect($this->property->companies()->count())->toBe(0);
});

test('the template downloads as csv', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.companies.template', ['property' => $this->property->code, 'format' => 'csv']))
        ->assertOk()
        ->assertDownload('companies.csv');
});
