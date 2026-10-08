<?php

use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\PaymentPurpose;
use App\Enums\ReportKey;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Reports\DepositLiabilityReport;
use App\Services\Reports\FolioBalancesOnDate;
use App\Services\Reports\OutstandingBalanceReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function balanceSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

function balanceUrl(Property $property, string $report, string $query = ''): string
{
    return "/p/{$property->code}/reports/{$report}".$query;
}

/**
 * A folio on a new reservation with one unit.
 *
 * @param  array<string, mixed>  $folio
 * @param  array<string, mixed>  $unit
 */
function balanceFolio(Property $property, string $arrival = '2026-10-08', string $departure = '2026-10-12', array $folio = [], array $unit = []): Folio
{
    $booking = Reservation::factory()->create(['property_id' => $property->id]);
    ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'status' => UnitStatus::Confirmed,
        ...$unit,
    ]);

    return Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $booking->id, ...$folio]);
}

function balanceLine(Folio $folio, string $amount, string $date = '2026-10-08', TransactionKind $kind = TransactionKind::Charge): FolioLine
{
    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
    ]);
}

/**
 * A payment of the positive amount, or a refund of it when `$refundOf` is given, with its ledger line.
 */
function balancePayment(Folio $folio, string $amount, string $date = '2026-10-08', PaymentPurpose $purpose = PaymentPurpose::Settlement, ?Payment $refundOf = null): Payment
{
    $method = PaymentMethod::factory()->create(['property_id' => $folio->property_id]);
    $line = balanceLine($folio, $refundOf === null ? "-{$amount}" : $amount, $date, TransactionKind::Payment);

    return Payment::factory()->create([
        'folio_id' => $folio->id,
        'property_id' => $folio->property_id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'purpose' => $purpose,
        'refund_of_id' => $refundOf?->id,
        'receipt_number' => $refundOf === null ? 'R-'.fake()->unique()->numerify('######') : null,
        'folio_line_id' => $line->id,
    ]);
}

test('the balance on a past date ignores the lines posted after it', function () {
    [, $property] = balanceSetup();
    $folio = balanceFolio($property);
    balanceLine($folio, '300.00', '2026-10-08');
    balancePayment($folio, '100.00', '2026-10-09');
    balanceLine($folio, '50.00', '2026-10-10');

    $service = app(FolioBalancesOnDate::class);

    expect((string) $service->forDate($property, '2026-10-08')[$folio->id]->balance)->toBe('300.00')
        ->and((string) $service->forDate($property, '2026-10-09')[$folio->id]->balance)->toBe('200.00')
        ->and((string) $service->forDate($property, '2026-10-10')[$folio->id]->balance)->toBe('250.00')
        ->and($service->forDate($property, '2026-10-07'))->toBe([]);
});

test('voided folios, settled folios and zero balances are in neither report', function () {
    [, $property] = balanceSetup();
    $voided = balanceFolio($property, folio: ['status' => FolioStatus::Voided]);
    balanceLine($voided, '80.00');
    $settled = balanceFolio($property, folio: ['status' => FolioStatus::Closed]);
    balanceLine($settled, '120.00');
    balanceLine($settled, '-120.00', '2026-10-09', TransactionKind::Payment);
    $zero = balanceFolio($property);
    balanceLine($zero, '60.00');
    balancePayment($zero, '60.00');
    $filters = new ReportFilters('2026-10-10', '2026-10-10');

    expect(app(OutstandingBalanceReport::class)->rows($property, $filters))->toBe([])
        ->and(app(DepositLiabilityReport::class)->rows($property, $filters))->toBe([])
        ->and(app(FolioBalancesOnDate::class)->forDate($property, '2026-10-10', true))->toBe([]);
});

test('a debit balance is outstanding and a credit balance is a deposit liability', function () {
    [$user, $property] = balanceSetup();
    $owes = balanceFolio($property);
    balanceLine($owes, '200.00');
    $credit = balanceFolio($property, '2026-10-20', '2026-10-22');
    balancePayment($credit, '150.00');

    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/outstanding-balance')
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->all() === [$owes->id])
        ->where('rows.data.0.balance', '200.00'));
    $this->actingAs($user)->get(balanceUrl($property, 'deposit-liability'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/deposit-liability')
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->all() === [$credit->id])
        ->where('rows.data.0.held', '150.00'));
});

test('payments are negative in outstanding balance and charges plus payments equal the balance', function () {
    [$user, $property] = balanceSetup();
    $folio = balanceFolio($property);
    balanceLine($folio, '500.00');
    balanceLine($folio, '20.00', kind: TransactionKind::Tax);
    $paid = balancePayment($folio, '200.00');
    balancePayment($folio, '50.00', '2026-10-09', refundOf: $paid);

    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.charges', '520.00')
        ->where('rows.data.0.payments', '-150.00')
        ->where('rows.data.0.balance', '370.00'));
});

test('deposit liability counts refunds as refunded, not received, and holds the security deposit net of refunds', function () {
    [$user, $property] = balanceSetup();
    $folio = balanceFolio($property, '2026-10-10', '2026-10-12');
    $advance = balancePayment($folio, '300.00', purpose: PaymentPurpose::Advance);
    $deposit = balancePayment($folio, '200.00', purpose: PaymentPurpose::SecurityDeposit);
    balancePayment($folio, '80.00', '2026-10-09', PaymentPurpose::SecurityDeposit, refundOf: $deposit);
    balancePayment($folio, '30.00', '2026-10-09', PaymentPurpose::Advance, refundOf: $advance);
    balanceLine($folio, '100.00', '2026-10-10');

    $this->actingAs($user)->get(balanceUrl($property, 'deposit-liability'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.received', '500.00')
        ->where('rows.data.0.refunded', '110.00')
        ->where('rows.data.0.charged', '100.00')
        ->where('rows.data.0.deposit', '120.00')
        ->where('rows.data.0.held', '290.00'));

    $before = app(FolioBalancesOnDate::class)->forDate($property, '2026-10-08')[$folio->id];
    expect((string) $before->securityDeposit)->toBe('200.00');
});

test('days since departure sorts newest debt first with blanks last, and the totals add up', function () {
    [$user, $property] = balanceSetup();
    $old = balanceFolio($property, '2026-10-01', '2026-10-03');
    balanceLine($old, '100.00', '2026-10-01');
    $recent = balanceFolio($property, '2026-10-05', '2026-10-09');
    balanceLine($recent, '70.50', '2026-10-05');
    $future = balanceFolio($property, '2026-10-12', '2026-10-14');
    balanceLine($future, '30.25', '2026-10-09');
    $onDeparture = balanceFolio($property, '2026-10-08', '2026-10-10');
    balanceLine($onDeparture, '10.00', '2026-10-08');
    balancePayment($onDeparture, '4.00', '2026-10-09');

    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', '-days')
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->all() === [$old->id, $recent->id, $onDeparture->id, $future->id])
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('days')->all() === [7, 1, 0, null])
        ->where('totals.figures.charges', '210.75')
        ->where('totals.figures.payments', '-4.00')
        ->where('totals.figures.balance', '206.75'));
});

test('deposit liability sorts by arrival and totals every money column', function () {
    [$user, $property] = balanceSetup();
    $later = balanceFolio($property, '2026-10-20', '2026-10-22');
    balancePayment($later, '100.00');
    $sooner = balanceFolio($property, '2026-10-12', '2026-10-13');
    balancePayment($sooner, '60.00', purpose: PaymentPurpose::SecurityDeposit);
    balanceLine($sooner, '10.00');

    $this->actingAs($user)->get(balanceUrl($property, 'deposit-liability'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', 'arrival')
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->all() === [$sooner->id, $later->id])
        ->where('totals.figures.received', '160.00')
        ->where('totals.figures.refunded', '0.00')
        ->where('totals.figures.charged', '10.00')
        ->where('totals.figures.deposit', '60.00')
        ->where('totals.figures.held', '150.00'));
});

test('each row shows the payer, the dates and today status of the unit or the reservation', function () {
    [$user, $property] = balanceSetup();
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel']);
    $master = balanceFolio($property, '2026-10-08', '2026-10-09', ['payer_company_id' => $company->id]);
    ReservationUnit::factory()->for($master->reservation)->create(['arrival_date' => '2026-10-07', 'departure_date' => '2026-10-11']);
    balanceLine($master, '10.00');
    $unitFolio = balanceFolio($property, '2026-10-05', '2026-10-06', unit: ['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDays(5), 'checked_out_at' => now()->subDays(4)]);
    $unitFolio->forceFill(['type' => 'unit', 'reservation_unit_id' => $unitFolio->reservation->units()->first()->id])->save();
    balanceLine($unitFolio, '20.00');

    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance', '?sort=folio'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.payer', 'Borneo Travel')
        ->where('rows.data.0.arrival', '2026-10-07')
        ->where('rows.data.0.departure', '2026-10-11')
        ->where('rows.data.0.status.value', 'confirmed')
        ->where('rows.data.1.arrival', '2026-10-05')
        ->where('rows.data.1.status.value', 'checked_out')
        ->where('rows.data.1.payer', $unitFolio->reservation->booker->full_name));
});

test('another property is not in the report and its URL is a 404', function () {
    [$user, $property] = balanceSetup();
    [, $other] = balanceSetup();
    $mine = balanceFolio($property);
    balanceLine($mine, '10.00');
    balanceLine(balanceFolio($other), '99.00');
    balancePayment(balanceFolio($other), '99.00');

    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance'))->assertInertia(fn (Assert $page) => $page->where('rows.meta.total', 1));
    $this->actingAs($user)->get(balanceUrl($property, 'deposit-liability'))->assertInertia(fn (Assert $page) => $page->where('rows.meta.total', 0));
    $this->actingAs($user)->get(balanceUrl($other, 'outstanding-balance'))->assertNotFound();
});

test('the pages need reports.view', function () {
    [$front, $property] = balanceSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($front)->get(balanceUrl($property, 'outstanding-balance'))->assertForbidden();
    $this->actingAs($front)->get(balanceUrl($property, 'deposit-liability'))->assertForbidden();
});

test('the date opens on the business date and a date in the URL replaces it', function () {
    [$user, $property] = balanceSetup();
    $folio = balanceFolio($property);
    balanceLine($folio, '100.00', '2026-10-08');
    balanceLine($folio, '-100.00', '2026-10-10', TransactionKind::Payment);

    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance'))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10')
        ->where('maxRangeDays', null)
        ->where('rows.meta.total', 0));
    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance', '?date=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-09')
        ->where('rows.meta.total', 1));
    $this->actingAs($user)->get(balanceUrl($property, 'deposit-liability', '?date=bogus'))->assertSessionHasErrors('date');
});

test('the status note shows only when the date is not the business date', function () {
    [$user, $property] = balanceSetup();
    balanceLine(balanceFolio($property), '10.00');

    foreach (['outstanding-balance', 'deposit-liability'] as $report) {
        $this->actingAs($user)->get(balanceUrl($property, $report))->assertInertia(fn (Assert $page) => $page->where('note', null));
        $this->actingAs($user)->get(balanceUrl($property, $report, '?date=2026-10-09'))->assertInertia(fn (Assert $page) => $page->where('note', 'Status as of today'));
    }
});

test('the registry lists both reports as one-date landscape finance reports', function () {
    foreach ([ReportKey::OutstandingBalance, ReportKey::DepositLiability] as $key) {
        expect($key->maxRangeDays())->toBeNull()
            ->and($key->category()->value)->toBe('finance')
            ->and($key->defaultOrientation()->value)->toBe('landscape')
            ->and(collect($key->columns())->every(fn ($column): bool => $column->defaultVisible))->toBeTrue();
    }

    expect(ReportKey::OutstandingBalance->report())->toBeInstanceOf(OutstandingBalanceReport::class)
        ->and(ReportKey::DepositLiability->report())->toBeInstanceOf(DepositLiabilityReport::class);
});

test('the PDF and the spreadsheet export only the selected rows and the PDF carries the note', function () {
    [$user, $property] = balanceSetup();
    $first = balanceFolio($property);
    balanceLine($first, '10.00');
    $second = balanceFolio($property);
    balanceLine($second, '20.00');
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/outstanding-balance/pdf", ['date' => '2026-10-09', 'rowKeys' => [$second->id]])->assertOk();
    $csv = $this->actingAs($user)->post("/p/{$property->code}/reports/outstanding-balance/spreadsheet", ['format' => 'csv', 'date' => '2026-10-10', 'rowKeys' => [$first->id]]);
    $lines = array_values(array_filter(explode("\n", ltrim($csv->streamedContent(), "\xEF\xBB\xBF"))));

    expect($captured->data['rows'])->toHaveCount(1)
        ->and($captured->data['rows'][0]->key)->toBe($second->id)
        ->and($captured->data['note'])->toBe('Status as of today')
        ->and($csv->headers->get('Content-Disposition'))->toContain('outstanding-balance-2026-10-10.csv')
        ->and($lines)->toHaveCount(3)
        ->and($lines[1])->toContain($first->number);
});

test('more folios add no queries', function () {
    [$user, $property] = balanceSetup();
    $make = function () use ($property): void {
        $debit = balanceFolio($property);
        balanceLine($debit, '10.00');
        $credit = balanceFolio($property);
        balancePayment($credit, '10.00');
    };
    $make();
    $this->actingAs($user)->get(balanceUrl($property, 'outstanding-balance'));
    $counts = [];

    foreach (['outstanding-balance', 'deposit-liability'] as $report) {
        DB::enableQueryLog();
        $this->get(balanceUrl($property, $report));
        $counts[$report] = count(DB::getQueryLog());
        DB::flushQueryLog();
    }

    foreach (range(1, 5) as $ignored) {
        $make();
    }

    foreach (['outstanding-balance', 'deposit-liability'] as $report) {
        DB::flushQueryLog();
        $this->get(balanceUrl($property, $report));

        expect(count(DB::getQueryLog()))->toBe($counts[$report]);
    }
});
