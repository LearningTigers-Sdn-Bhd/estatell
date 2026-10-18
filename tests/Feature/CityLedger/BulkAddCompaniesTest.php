<?php

use App\Enums\CompanyType;
use App\Enums\DefaultRoleEnum;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

function bulkCompaniesUrl(Property $property, string $suffix = ''): string
{
    return "/p/{$property->code}/finance/city-ledger/companies{$suffix}";
}

/**
 * @param  list<list<string>>  $rows  the first row is the header
 */
function companyImportFile(array $rows, string $extension = 'csv'): UploadedFile
{
    $path = sys_get_temp_dir().'/'.uniqid('company-import-').'.'.$extension;
    $writer = SimpleExcelWriter::create($path)->noHeaderRow();
    foreach ($rows as $row) {
        $writer->addRow($row);
    }
    $writer->close();

    return new UploadedFile($path, 'companies.'.$extension, null, null, true);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bulkCompanyRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'Borneo Travel',
        'type' => 'travel_agent',
        'contact_email' => '',
        'contact_phone' => '',
        'credit_limit' => '',
        'payment_terms_days' => '',
        'direct_bill_enabled' => true,
        'brn' => '',
        'tin' => '',
        'sst_registration_number' => '',
        'billing_address_line1' => '',
        'billing_address_line2' => '',
        'billing_city' => '',
        'billing_postal_code' => '',
        'billing_state_code' => '',
        'billing_country_code' => '',
    ], $overrides);
}

test('the bulk add page needs company.manage and sends the options', function () {
    $owner = User::factory()->owner()->create();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($owner)
        ->get(bulkCompaniesUrl($owner->property, '/bulk'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/city-ledger/companies/bulk')
            ->has('types', 3)
            ->has('modes', 2)
            ->has('countries')
            ->where('maxRows', 200)
        );

    $this->actingAs($frontDesk)->get(bulkCompaniesUrl($frontDesk->property, '/bulk'))->assertForbidden();
    $this->actingAs($frontDesk)->post(bulkCompaniesUrl($frontDesk->property, '/bulk'), ['rows' => [bulkCompanyRow()]])->assertForbidden();
});

test('bulk add creates every row with simple and detailed fields and logs each', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(bulkCompaniesUrl($property, '/bulk'), ['rows' => [
            bulkCompanyRow(['contact_email' => 'Acc@Borneo.my', 'contact_phone' => '012-345 6789', 'credit_limit' => '20000', 'payment_terms_days' => '30']),
            bulkCompanyRow(['name' => 'JKR Sabah', 'type' => 'government', 'direct_bill_enabled' => false, 'brn' => '19990101', 'billing_city' => 'Kota Kinabalu', 'billing_country_code' => 'MY']),
        ]])
        ->assertRedirect(bulkCompaniesUrl($property))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 companies added.']);

    $borneo = $property->companies()->where('name', 'Borneo Travel')->sole();
    $jkr = $property->companies()->where('name', 'JKR Sabah')->sole();

    expect($borneo->type)->toBe(CompanyType::TravelAgent)
        ->and($borneo->contact_email)->toBe('acc@borneo.my')
        ->and($borneo->contact_phone)->toBe('+60123456789')
        ->and($borneo->credit_limit)->toBe('20000.00')
        ->and($borneo->payment_terms_days)->toBe(30)
        ->and($borneo->direct_bill_enabled)->toBeTrue()
        ->and($borneo->brn)->toBeNull()
        ->and($jkr->direct_bill_enabled)->toBeFalse()
        ->and($jkr->brn)->toBe('19990101')
        ->and($jkr->billing_country_code)->toBe('MY')
        ->and(Activity::query()->where('event', 'company.created')->count())->toBe(2);
});

test('one bad row saves nothing and the errors point at their rows', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Company::factory()->for($property)->create(['name' => 'Petronas Miri']);

    $this->actingAs($owner)
        ->post(bulkCompaniesUrl($property, '/bulk'), ['rows' => [
            bulkCompanyRow(),
            bulkCompanyRow(['name' => 'BORNEO travel']),
            bulkCompanyRow(['name' => 'petronas miri']),
            bulkCompanyRow(['name' => 'Agency', 'type' => 'agency']),
            bulkCompanyRow(['name' => 'Far Away', 'billing_country_code' => 'XX']),
            bulkCompanyRow(['name' => '']),
        ]])
        ->assertSessionHasErrors([
            'rows.1.name' => 'This name is in the list twice.',
            'rows.2.name' => 'Another company already uses this name.',
            'rows.3.type',
            'rows.4.billing_country_code',
            'rows.5.name' => 'Enter a name.',
        ]);

    expect($property->companies()->count())->toBe(1);
});

test('bulk add takes at most 200 rows', function () {
    $owner = User::factory()->owner()->create();

    $rows = array_map(fn (int $i): array => bulkCompanyRow(['name' => "Company {$i}"]), range(1, 201));

    $this->actingAs($owner)
        ->post(bulkCompaniesUrl($owner->property, '/bulk'), ['rows' => $rows])
        ->assertSessionHasErrors('rows');
});

test('a simple file fills rows and reads types, yes and no leniently', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(bulkCompaniesUrl($owner->property, '/import'), ['file' => companyImportFile([
            ['Name', ' Type ', 'EMAIL', 'phone', 'credit_limit', 'terms_days', 'direct_billing', 'notes'],
            ['Borneo Travel', 'Travel agent', 'Acc@Borneo.my', '0123456789', '20000', '30', 'no', 'ignored'],
            ['', '', '', '', '', '', '', ''],
            ['JKR Sabah', 'agency', '', '', '', '', 'maybe', ''],
        ])])
        ->assertOk()
        ->assertJsonPath('mode', 'simple')
        ->assertJsonPath('rows.0.name', 'Borneo Travel')
        ->assertJsonPath('rows.0.type', 'travel_agent')
        ->assertJsonPath('rows.0.contact_email', 'acc@borneo.my')
        ->assertJsonPath('rows.0.payment_terms_days', '30')
        ->assertJsonPath('rows.0.direct_bill_enabled', false)
        ->assertJsonPath('rows.0.problems', [])
        ->assertJsonPath('rows.1.type', '')
        ->assertJsonPath('rows.1.direct_bill_enabled', true)
        ->assertJsonPath('rows.1.problems', ['type' => 'Unknown type "agency".', 'direct_bill_enabled' => 'Write yes or no.'])
        ->assertJsonCount(2, 'rows');

    expect(Company::query()->count())->toBe(0);
});

test('a file with detailed columns switches to detailed and reads countries by name', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(bulkCompaniesUrl($owner->property, '/import'), ['file' => companyImportFile([
            ['name', 'type', 'brn', 'city', 'country'],
            ['Borneo Travel', 'corporate', '202001012345', 'Kota Kinabalu', 'malaysia'],
            ['Far Away', 'corporate', '', '', 'Narnia'],
        ], 'xlsx')])
        ->assertOk()
        ->assertJsonPath('mode', 'detailed')
        ->assertJsonPath('rows.0.brn', '202001012345')
        ->assertJsonPath('rows.0.billing_city', 'Kota Kinabalu')
        ->assertJsonPath('rows.0.billing_country_code', 'MY')
        ->assertJsonPath('rows.1.billing_country_code', '')
        ->assertJsonPath('rows.1.problems.billing_country_code', 'Unknown country "Narnia".');
});

test('imports refuse a file without a name column, too many rows and other file types', function () {
    $owner = User::factory()->owner()->create();
    $url = bulkCompaniesUrl($owner->property, '/import');

    $this->actingAs($owner)
        ->postJson($url, ['file' => companyImportFile([['company', 'type'], ['Borneo', 'corporate']])])
        ->assertJsonValidationErrors(['file' => 'The first row must have a name column. Download the template to see every column.']);

    $tooMany = [['name'], ...array_map(fn (int $i): array => ["Company {$i}"], range(1, 201))];
    $this->postJson($url, ['file' => companyImportFile($tooMany)])
        ->assertJsonValidationErrors(['file' => 'The file has more than 200 rows.']);

    $this->postJson($url, ['file' => UploadedFile::fake()->create('companies.pdf', 10, 'application/pdf')])
        ->assertJsonValidationErrors('file');
});

test('the import template has the columns of the chosen mode', function (string $format, string $mode, int $columns) {
    $owner = User::factory()->owner()->create();

    $response = $this->actingAs($owner)
        ->get(bulkCompaniesUrl($owner->property, "/import-template.{$format}?mode={$mode}"))
        ->assertOk()
        ->assertDownload("companies-{$mode}.{$format}");

    $copy = sys_get_temp_dir().'/'.uniqid('template-').'.'.$format;
    copy($response->baseResponse->getFile()->getPathname(), $copy);
    $headers = SimpleExcelReader::create($copy)->getHeaders();

    expect($headers)->toHaveCount($columns)
        ->and(array_slice($headers, 0, 9))->toBe(['name', 'type', 'email', 'phone', 'credit_limit', 'terms_days', 'direct_billing', 'portal_email', 'cancel_days']);
})->with([
    ['csv', 'simple', 9],
    ['xlsx', 'simple', 9],
    ['csv', 'detailed', 18],
]);

test('the import template only comes as csv or xlsx', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get(bulkCompaniesUrl($owner->property, '/import-template.pdf'))->assertNotFound();
});
