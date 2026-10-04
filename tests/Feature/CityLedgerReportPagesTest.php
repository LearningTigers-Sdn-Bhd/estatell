<?php

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\ReservationResult;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Reservations\BookingErrorKeys;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A live property whose business date is today, and a user with the role.
 *
 * @return array{user: User, property: Property}
 */
function reportPagesSetup(DefaultRoleEnum $role = DefaultRoleEnum::Finance): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => today()->toDateString()]);

    return ['user' => $user, 'property' => $property];
}

function reportUrl(array $setup, string $path): string
{
    return "/p/{$setup['property']->code}/finance/city-ledger/{$path}";
}

test('the aging page shows companies with their buckets and totals', function () {
    $setup = reportPagesSetup();
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Borneo Travel']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'amount' => '300.00', 'due_on' => today()->subDays(40)->toDateString()]);
    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'amount' => '50.00']);

    $this->actingAs($setup['user'])->get(reportUrl($setup, 'aging'))->assertInertia(fn (Assert $page) => $page
        ->component('finance/city-ledger/aging/index')
        ->where('report.asAt', today()->toDateString())
        ->has('report.rows', 1)
        ->where('report.rows.0.companyName', 'Borneo Travel')
        ->where('report.rows.0.days31to60', '300.00')
        ->where('report.rows.0.credit', '-50.00')
        ->where('report.rows.0.total', '250.00')
        ->where('report.totals.total', '250.00')
        ->where('today', today()->toDateString()));
});

test('the as at date is used and never goes past the business date', function () {
    $setup = reportPagesSetup();
    $company = Company::factory()->for($setup['property'])->create();
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'amount' => '100.00', 'due_on' => today()->subDays(10)->toDateString()]);
    $this->actingAs($setup['user']);

    $this->get(reportUrl($setup, 'aging?as_at='.today()->subDays(20)->toDateString()))->assertInertia(fn (Assert $page) => $page
        ->where('report.rows.0.current', '100.00'));
    $this->get(reportUrl($setup, 'aging?as_at='.today()->addDays(20)->toDateString()))->assertInertia(fn (Assert $page) => $page
        ->where('report.asAt', today()->toDateString())
        ->where('report.rows.0.days1to30', '100.00'));
});

test('the aging CSV and PDF download', function () {
    $setup = reportPagesSetup();
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Acme']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'amount' => '75.00', 'due_on' => today()->toDateString()]);
    $this->actingAs($setup['user']);

    $csv = $this->get(reportUrl($setup, 'aging/csv'))->assertOk();

    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('Company,Current,1-30,31-60,61-90,"Over 90",Credit,Total')
        ->and($csv->streamedContent())->toContain('Acme,75.00,0.00,0.00,0.00,0.00,0.00,75.00')
        ->and($csv->streamedContent())->toContain('Total,75.00');

    expect($this->get(reportUrl($setup, 'aging/pdf'))->assertOk()->headers->get('content-type'))->toContain('application/pdf');
});

test('the aging pages need company.view and stay inside the property', function () {
    $setup = reportPagesSetup(DefaultRoleEnum::Housekeeping);
    ArInvoice::factory()->create(['due_on' => today()->subDays(5)->toDateString()]);

    $this->actingAs($setup['user'])->get(reportUrl($setup, 'aging'))->assertForbidden();
    $this->get(reportUrl($setup, 'aging/csv'))->assertForbidden();
    $this->get(reportUrl($setup, 'aging/pdf'))->assertForbidden();

    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($finance)->get(reportUrl($setup, 'aging'))->assertInertia(fn (Assert $page) => $page->has('report.rows', 0));
});

test('the aging page runs the same queries however many companies there are', function () {
    $setup = reportPagesSetup();
    $this->actingAs($setup['user']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => Company::factory()->for($setup['property'])->create()->id]);
    $this->get(reportUrl($setup, 'aging'));

    DB::enableQueryLog();
    $this->get(reportUrl($setup, 'aging'));
    $few = count(DB::getQueryLog());

    foreach (range(1, 6) as $ignored) {
        ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => Company::factory()->for($setup['property'])->create()->id]);
    }

    DB::flushQueryLog();
    $this->get(reportUrl($setup, 'aging'));

    expect(count(DB::getQueryLog()))->toBe($few);
});

test('the Statement tab defaults to this month and takes a range', function () {
    $setup = reportPagesSetup();
    $company = Company::factory()->for($setup['property'])->create();
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'amount' => '200.00', 'issued_on' => today()->startOfMonth()->toDateString(), 'number' => 'F-1']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'amount' => '50.00', 'issued_on' => today()->subMonths(2)->toDateString(), 'number' => 'F-0']);
    $url = reportUrl($setup, "companies/{$company->id}/statement");
    $this->actingAs($setup['user']);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->component('finance/city-ledger/companies/show')
        ->where('tab', 'statement')
        ->where('statement.from', today()->startOfMonth()->toDateString())
        ->where('statement.to', today()->toDateString())
        ->where('statement.openingBalance', '50.00')
        ->has('statement.lines', 1)
        ->where('statement.closingBalance', '250.00'));
    $this->get($url.'?from='.today()->subMonths(3)->toDateString().'&to='.today()->addDays(9)->toDateString())->assertInertia(fn (Assert $page) => $page
        ->where('statement.to', today()->toDateString())
        ->where('statement.openingBalance', '0.00')
        ->has('statement.lines', 2));
    $this->get($url.'?from='.today()->toDateString().'&to='.today()->subDays(5)->toDateString())->assertInertia(fn (Assert $page) => $page
        ->where('statement.from', today()->subDays(5)->toDateString()));
});

test('the statement PDF downloads and another property company is a 404', function () {
    $setup = reportPagesSetup(DefaultRoleEnum::FrontDesk);
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Acme Sdn Bhd']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id]);
    $foreign = Company::factory()->create();
    $this->actingAs($setup['user']);

    $response = $this->get(reportUrl($setup, "companies/{$company->id}/statement.pdf"))->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');

    $this->get(reportUrl($setup, "companies/{$foreign->id}/statement.pdf"))->assertNotFound();
    $this->get(reportUrl($setup, "companies/{$foreign->id}/statement"))->assertNotFound();
});

test('the company page and list show credit exposure and flag a company over its limit', function () {
    $setup = reportPagesSetup();
    $over = Company::factory()->for($setup['property'])->create(['name' => 'Over', 'credit_limit' => '100.00']);
    $fine = Company::factory()->for($setup['property'])->create(['name' => 'Fine', 'credit_limit' => '100000.00']);
    $free = Company::factory()->for($setup['property'])->create(['name' => 'Free', 'credit_limit' => null]);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $over->id, 'amount' => '500.00']);
    $this->actingAs($setup['user']);

    $this->get(reportUrl($setup, 'companies'))->assertInertia(fn (Assert $page) => $page
        ->where('companies.0.name', 'Fine')
        ->where('companies.0.overLimit', false)
        ->where('companies.1.name', 'Free')
        ->where('companies.1.overLimit', false)
        ->where('companies.2.name', 'Over')
        ->where('companies.2.overLimit', true));
    $this->get(reportUrl($setup, "companies/{$over->id}/credit"))->assertInertia(fn (Assert $page) => $page
        ->where('company.credit.exposure', '500.00')
        ->where('company.credit.openAr', '500.00')
        ->where('company.credit.headroom', '-400.00')
        ->where('company.credit.overLimit', true));
    $this->get(reportUrl($setup, "companies/{$free->id}/credit"))->assertInertia(fn (Assert $page) => $page
        ->where('company.credit.limit', null)
        ->where('company.credit.headroom', null)
        ->where('company.credit.overLimit', false));
    expect($fine->id)->not->toBe($over->id);
});

test('the companies list runs the same queries however many companies there are', function () {
    $setup = reportPagesSetup();
    $this->actingAs($setup['user']);
    Company::factory()->count(2)->for($setup['property'])->create();
    $this->get(reportUrl($setup, 'companies'));

    DB::enableQueryLog();
    $this->get(reportUrl($setup, 'companies'));
    $few = count(DB::getQueryLog());
    Company::factory()->count(8)->for($setup['property'])->create();
    DB::flushQueryLog();
    $this->get(reportUrl($setup, 'companies'));

    expect(count(DB::getQueryLog()))->toBe($few);
});

test('an over-limit settle comes back as a field error and the page says who may override', function (DefaultRoleEnum $role, bool $canOverride) {
    $setup = reportPagesSetup($role);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $setup['property']->id]);
    $company = Company::factory()->for($setup['property'])->create(['credit_limit' => '10.00']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'amount' => '600.00']);
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'posting_date' => today()->toDateString()]);
    $url = route('reservations.folios.city-ledger', ['property' => $setup['property']->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);
    $this->actingAs($setup['user']);

    $this->post($url)->assertSessionHasErrors('credit_override_reason');
    $this->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $folio->reservation_id]))
        ->assertInertia(fn (Assert $page) => $page->where('canOverrideCredit', $canOverride));
})->with([
    'owner' => [DefaultRoleEnum::Owner, true],
    'finance' => [DefaultRoleEnum::Finance, false],
]);

test('changing the company over the limit comes back as a credit field error', function () {
    $setup = reportPagesSetup(DefaultRoleEnum::Owner);
    $company = Company::factory()->for($setup['property'])->create(['credit_limit' => '10.00']);
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    ReservationUnit::factory()->for($reservation)->withNights()->create();
    $url = route('reservations.company.update', ['property' => $setup['property']->code, 'reservation' => $reservation->id]);
    $this->actingAs($setup['user']);

    $this->put($url, ['company_id' => $company->id])->assertSessionHasErrors('credit_override_reason');

    expect($reservation->fresh()->company_id)->toBeNull();

    $this->put($url, ['company_id' => $company->id, 'credit_override_reason' => 'Owner agreed'])->assertSessionHasNoErrors();

    expect($reservation->fresh()->company_id)->toBe($company->id);
});

test('the sidebar lists Aging after Invoices', function () {
    $setup = reportPagesSetup();

    $this->actingAs($setup['user'])->get(reportUrl($setup, 'aging'))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.contexts.1.groups.0.items.2.title', 'Aging')
        ->where('navigation.contexts.1.groups.0.items.2.active', true));
});

test('a credit refusal of a booking keys its error to the credit reason field', function () {
    $errors = app(BookingErrorKeys::class)->errors(BookingResult::refused(ReservationResult::REFUSAL_CREDIT_LIMIT_EXCEEDED), [1], false);

    expect(array_keys($errors))->toBe(['credit_override_reason'])
        ->and($errors['credit_override_reason'])->toBe('This company is over its credit limit.');
});
