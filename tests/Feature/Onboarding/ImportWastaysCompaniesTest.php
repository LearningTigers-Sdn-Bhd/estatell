<?php

use App\Actions\Onboarding\Wastays\RecordImportLink;
use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\CompanyAccess;
use App\Enums\CompanyMarket;
use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use App\Enums\PaymentMethodType;
use App\Enums\PropertyType;
use App\Models\Company;
use App\Models\ImportLink;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Testing\PendingCommand;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
});

/**
 * @return array<string, list<array<string, string>>>
 */
function wastaysCompanyTables(): array
{
    $company = fn (string $id, string $account, array $extra = []): array => [
        'id' => $id, 'corporate_account_id' => $account, 'account_type' => 'travel_agent', 'relationship_type' => 'direct_bill', 'market' => 'local',
        'status' => 'active', 'contact_email' => '', 'contact_phone' => '', 'credit_limit' => '', 'payment_terms_days' => '30', 'brn' => '', 'tin' => '',
        'sst_registration_number' => '', 'billing_address_line1' => '', 'billing_address_line2' => '', 'billing_city' => '', 'billing_postal_code' => '',
        ...$extra,
    ];

    return [
        'accounts' => [
            ['id' => '1', 'name' => 'Super Sightseeing Holidays'],
            ['id' => '2', 'name' => 'Beijing Pipikou International'],
            ['id' => '3', 'name' => 'super sightseeing holidays'],
            ['id' => '4', 'name' => 'Old Hotel Group'],
        ],
        'hotel_corporate_accounts' => [
            $company('8', '1', ['contact_email' => 'Accounts@Super.test', 'contact_phone' => '+60123456789', 'credit_limit' => '5000', 'market' => 'international']),
            $company('9', '2', ['contact_email' => 'not an email', 'contact_phone' => 'abc', 'account_type' => 'company']),
            $company('10', '3'),
            $company('11', '4', ['status' => 'suspended', 'account_type' => 'government', 'relationship_type' => 'standard']),
        ],
        'rate_plan_agency_rules' => [['id' => '1', 'rate_plan_id' => '13', 'hotel_corporate_account_id' => '8']],
    ];
}

function runCompanyImport(?array $tables = null, array $options = []): PendingCommand
{
    return test()->artisan('wastays:import-companies', [
        'bundle' => wastaysBundleDir($tables ?? wastaysCompanyTables()),
        'property' => test()->property->code,
        '--actor' => test()->admin->email,
        ...$options,
    ]);
}

it('creates the companies with their terms, merges a repeated name and turns City ledger on', function () {
    runCompanyImport()->assertSuccessful();

    $companies = $this->property->companies()->orderBy('name')->get()->keyBy('name');

    expect($companies->keys()->all())->toBe(['Beijing Pipikou International', 'Old Hotel Group', 'Super Sightseeing Holidays'])
        ->and($companies['Super Sightseeing Holidays']->type)->toBe(CompanyType::TravelAgent)
        ->and($companies['Super Sightseeing Holidays']->market)->toBe(CompanyMarket::International)
        ->and($companies['Super Sightseeing Holidays']->contact_email)->toBe('accounts@super.test')
        ->and($companies['Super Sightseeing Holidays']->contact_phone)->toBe('+60123456789')
        ->and($companies['Super Sightseeing Holidays']->credit_limit)->toBe('5000.00')
        ->and($companies['Super Sightseeing Holidays']->payment_terms_days)->toBe(30)
        ->and($companies['Super Sightseeing Holidays']->direct_bill_enabled)->toBeTrue()
        ->and($companies['Beijing Pipikou International']->type)->toBe(CompanyType::Corporate)
        ->and($companies['Beijing Pipikou International']->contact_email)->toBeNull()
        ->and($companies['Beijing Pipikou International']->contact_phone)->toBeNull()
        ->and($companies['Old Hotel Group']->type)->toBe(CompanyType::Government)
        ->and($companies['Old Hotel Group']->direct_bill_enabled)->toBeFalse()
        ->and($companies['Old Hotel Group']->status)->toBe(CompanyStatus::Suspended)
        ->and(ImportLink::query()->where('source_table', 'hotel_corporate_accounts')->count())->toBe(4)
        ->and($this->property->paymentMethods()->where('type', PaymentMethodType::DirectBill)->value('active'))->toBeTrue();
});

it('imports the companies once, and writes nothing on a dry run', function () {
    runCompanyImport(options: ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(Company::query()->count())->toBe(0)->and(ImportLink::query()->count())->toBe(0);

    runCompanyImport()->assertSuccessful();
    runCompanyImport()->assertSuccessful();

    expect(Company::query()->count())->toBe(3);
});

it('lists the companies on the plan the setup limited to companies', function () {
    $plan = RatePlan::factory()->for($this->property)->create(['name' => 'Super Sightseeing', 'company_access' => CompanyAccess::Selected]);
    app(RecordImportLink::class)($this->property, 'rate_plans', '13', $plan);

    runCompanyImport()->assertSuccessful();

    expect($plan->companies()->pluck('name')->all())->toBe(['Super Sightseeing Holidays']);

    runCompanyImport()->assertSuccessful();

    expect($plan->companies()->count())->toBe(1);
});

it('refuses a bundle with no companies and a property that has gone live', function () {
    runCompanyImport(['guests' => [['id' => '1']]])->expectsOutputToContain('has no')->assertFailed();

    $this->property = Property::factory()->live()->create(['document_prefix' => 'GEH2']);
    $this->artisan('wastays:import-companies', [
        'bundle' => wastaysBundleDir(wastaysCompanyTables(), ['hotel_code' => 'GEH2']),
        'property' => $this->property->code,
        '--actor' => $this->admin->email,
    ])->expectsOutputToContain('already gone live')->assertFailed();
});
