<?php

use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentStatus;
use App\Enums\TransactionKind;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use App\Services\Reports\PaymentsReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function paymentsSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

function paymentsUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/payments".$query;
}

/**
 * A folio payment (or a refund) whose ledger line is posted on the business date given. `received_at` is the clock, so
 * the report must read the line.
 */
function reportPayment(Property $property, PaymentMethod $method, string $amount, string $date, PaymentStatus $status = PaymentStatus::Completed, ?Payment $refundOf = null, ?User $staff = null): Payment
{
    $folio = Folio::factory()->create(['property_id' => $property->id]);
    $line = FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $method->transaction_code_id,
        'kind' => TransactionKind::Payment,
        'amount' => $refundOf === null ? "-{$amount}" : $amount,
        'unit_amount' => $refundOf === null ? "-{$amount}" : $amount,
        'posting_date' => $date,
    ]);

    return Payment::factory()->create([
        'folio_id' => $folio->id,
        'property_id' => $property->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'status' => $status,
        'refund_of_id' => $refundOf?->id,
        'receipt_number' => $refundOf === null ? 'R-'.fake()->unique()->numerify('######') : null,
        'folio_line_id' => $line->id,
        ...($staff === null ? [] : ['received_by' => $staff->id]),
    ]);
}

/**
 * Two methods, with payments on 8 to 10 October: a refund, a pending and a failed payment, and city ledger payments
 * (one voided). Only the completed rows count.
 *
 * @return array{card: PaymentMethod, cash: PaymentMethod, rows: array<string, Payment|ArPayment>}
 */
function paymentsData(Property $property): array
{
    $card = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Card']);
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Cash']);
    $paid = reportPayment($property, $card, '300.00', '2026-10-09');
    $rows = [
        'paid' => $paid,
        'cash' => reportPayment($property, $cash, '80.00', '2026-10-10'),
        'refund' => reportPayment($property, $card, '50.00', '2026-10-10', refundOf: $paid),
        'pending' => reportPayment($property, $card, '20.00', '2026-10-10', PaymentStatus::Pending),
        'failed' => reportPayment($property, $card, '10.00', '2026-10-10', PaymentStatus::Failed),
        'outside' => reportPayment($property, $card, '999.00', '2026-10-01'),
        'ar' => ArPayment::factory()->create(['property_id' => $property->id, 'payment_method_id' => $cash->id, 'amount' => '500.00', 'received_on' => '2026-10-08']),
        'voided' => ArPayment::factory()->voided()->create(['property_id' => $property->id, 'payment_method_id' => $cash->id, 'amount' => '70.00', 'received_on' => '2026-10-08']),
    ];

    return ['card' => $card, 'cash' => $cash, 'rows' => $rows];
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = paymentsSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = paymentsSetup();
    [, $other] = paymentsSetup();
    paymentsData($own);
    paymentsData($other);

    $this->actingAs($front)->get(paymentsUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(paymentsUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(paymentsUrl($own, '?from=2026-10-08&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/payments')
        ->where('maxRangeDays', 366)
        ->where('sort', 'date')
        ->has('layout.columns', 9)
        ->where('rows.meta.total', 7));
});

test('rows use the business date, signed amounts, the city ledger and a Voided status', function () {
    [$user, $property] = paymentsSetup();
    $data = paymentsData($property);
    $rows = $data['rows'];

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10&sort=-amount'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($list) => collect($list)->map(fn ($row) => [$row['key'], $row['date'], $row['status']['label'], $row['amount']])->all() === [
            ["ar:{$rows['ar']->id}", '2026-10-08', 'Completed', '500.00'],
            ["p:{$rows['paid']->id}", '2026-10-09', 'Completed', '300.00'],
            ["p:{$rows['cash']->id}", '2026-10-10', 'Completed', '80.00'],
            ["ar:{$rows['voided']->id}", '2026-10-08', 'Voided', '70.00'],
            ["p:{$rows['pending']->id}", '2026-10-10', 'Pending', '20.00'],
            ["p:{$rows['failed']->id}", '2026-10-10', 'Failed', '10.00'],
            ["p:{$rows['refund']->id}", '2026-10-10', 'Completed', '-50.00'],
        ]));
});

test('only Completed payments count in the totals, and a refund subtracts', function () {
    [$user, $property] = paymentsSetup();
    paymentsData($property);

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.amount', '830.00'));

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-10&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 4)
        ->where('totals.figures.amount', '30.00'));
});

test('the totals for each method count only Completed rows', function () {
    [$user, $property] = paymentsSetup();
    paymentsData($property);

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('breakdown.title', 'Total by method')
        ->where('breakdown.headings', ['Method', 'Count', 'In', 'Out', 'Net'])
        ->where('breakdown.rows', [['Card', '2', '300.00', '-50.00', '250.00'], ['Cash', '2', '580.00', '0.00', '580.00']])
        ->where('breakdown.total', ['Total', '4', '880.00', '-50.00', '830.00'])
        ->where('breakdown.subtotalRows', []));
});

test('the payer is the reservation number or the company, and the staff and reference show', function () {
    [$user, $property] = paymentsSetup();
    $data = paymentsData($property);
    $paid = $data['rows']['paid'];
    $paid->load('folio.reservation', 'receiver');

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-09&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.payer', $paid->folio->reservation->reference)
        ->where('rows.data.0.staff', $paid->receiver->name)
        ->where('rows.data.0.receipt', $paid->receipt_number)
        ->where('rows.data.0.method', 'Card'));

    $ar = $data['rows']['ar'];
    $ar->load('company');

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-08'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($list) => collect($list)->pluck('payer')->contains($ar->company->name)));
});

test('the PDF of selected rows has its own totals and the totals for each method', function () {
    [$user, $property] = paymentsSetup();
    $rows = paymentsData($property)['rows'];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/payments/pdf", [
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'rowKeys' => ["p:{$rows['paid']->id}", "p:{$rows['refund']->id}", "p:{$rows['pending']->id}", "ar:{$rows['ar']->id}", "p:{$rows['outside']->id}"],
    ])->assertOk();

    expect($captured->data['rows'])->toHaveCount(4)
        ->and($captured->data['totals']->figures['amount'])->toBe('750.00')
        ->and($captured->data['breakdown']->rows)->toBe([['Card', '2', '300.00', '-50.00', '250.00'], ['Cash', '1', '500.00', '0.00', '500.00']])
        ->and($captured->data['breakdown']->total)->toBe(['Total', '3', '800.00', '-50.00', '750.00']);
});

test('the spreadsheet of selected rows has the total row and no summary table', function () {
    [$user, $property] = paymentsSetup();
    $rows = paymentsData($property)['rows'];

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/payments/spreadsheet", [
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'format' => 'csv',
        'sort' => 'date',
        'rowKeys' => ["p:{$rows['refund']->id}", "ar:{$rows['voided']->id}", "ar:{$rows['ar']->id}"],
    ]);
    $csv = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($csv)->toHaveCount(5)
        ->and($csv[0][0])->toBe('Date')
        ->and(array_column(array_slice($csv, 1, 3), 4))->toBe(['Completed', 'Voided', 'Completed'])
        ->and(end($csv)[6])->toBe('450');
});

test('the sidebar lists the finance reports under the Finance category', function () {
    [$user, $property] = paymentsSetup();

    $this->actingAs($user)->get(paymentsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.contexts.2.groups', fn ($groups) => collect(collect($groups)->firstWhere('label', 'Finance')['items'])->pluck('title')->all() === ['Payments', 'Folio ledger', 'Outstanding balance', 'Deposit liability']));
});

/**
 * Alice and Bob, with payments on 8 to 10 October. Alice: card 300 and a refund of 50 (and a pending and a failed
 * payment), cash 80. Bob: cash 120, a city ledger payment of 500 and a voided one.
 *
 * @return array{alice: User, bob: User, rows: array<string, Payment|ArPayment>}
 */
function cashUpData(Property $property): array
{
    $alice = User::factory()->create(['property_id' => $property->id, 'name' => 'Alice Cashier']);
    $bob = User::factory()->create(['property_id' => $property->id, 'name' => 'Bob Cashier']);
    $card = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Card']);
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Cash']);
    $paid = reportPayment($property, $card, '300.00', '2026-10-09', staff: $alice);

    return ['alice' => $alice, 'bob' => $bob, 'rows' => [
        'paid' => $paid,
        'refund' => reportPayment($property, $card, '50.00', '2026-10-10', refundOf: $paid, staff: $alice),
        'aliceCash' => reportPayment($property, $cash, '80.00', '2026-10-10', staff: $alice),
        'pending' => reportPayment($property, $card, '20.00', '2026-10-10', PaymentStatus::Pending, staff: $alice),
        'failed' => reportPayment($property, $card, '10.00', '2026-10-10', PaymentStatus::Failed, staff: $alice),
        'bobCash' => reportPayment($property, $cash, '120.00', '2026-10-10', staff: $bob),
        'ar' => ArPayment::factory()->create(['property_id' => $property->id, 'payment_method_id' => $cash->id, 'amount' => '500.00', 'received_on' => '2026-10-08', 'received_by' => $bob->id]),
        'voided' => ArPayment::factory()->voided()->create(['property_id' => $property->id, 'payment_method_id' => $cash->id, 'amount' => '70.00', 'received_on' => '2026-10-08', 'received_by' => $bob->id]),
    ]];
}

test('the cash-up has In, Out and Net for each staff member and method, with subtotals and a grand total', function () {
    [$user, $property] = paymentsSetup();
    cashUpData($property);

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10&group=staff'))->assertInertia(fn (Assert $page) => $page
        ->where('group', 'staff')
        ->where('breakdown.title', 'Cash-up by staff')
        ->where('breakdown.headings', ['Staff', 'Method', 'Count', 'In', 'Out', 'Net'])
        ->where('breakdown.rows', [
            ['Alice Cashier', 'Card', '2', '300.00', '-50.00', '250.00'],
            ['Alice Cashier', 'Cash', '1', '80.00', '0.00', '80.00'],
            ['Alice Cashier subtotal', '', '3', '380.00', '-50.00', '330.00'],
            ['Bob Cashier', 'Cash', '2', '620.00', '0.00', '620.00'],
            ['Bob Cashier subtotal', '', '2', '620.00', '0.00', '620.00'],
        ])
        ->where('breakdown.subtotalRows', [2, 4])
        ->where('breakdown.total', ['Total', '', '5', '1,000.00', '-50.00', '950.00'])
        ->where('totals.figures.amount', '950.00'));
});

test('the Staff group sorts by staff and keeps date order inside each staff member', function () {
    [$user, $property] = paymentsSetup();
    $rows = cashUpData($property)['rows'];

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10&group=staff'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', 'staff')
        ->where('rows.data', fn ($list) => collect($list)->pluck('key')->all() === [
            "p:{$rows['paid']->id}",
            "p:{$rows['refund']->id}",
            "p:{$rows['aliceCash']->id}",
            "p:{$rows['pending']->id}",
            "p:{$rows['failed']->id}",
            "ar:{$rows['ar']->id}",
            "ar:{$rows['voided']->id}",
            "p:{$rows['bobCash']->id}",
        ]));
});

test('an invalid group is refused, and Revenue does not take the staff group', function () {
    [$user, $property] = paymentsSetup();

    $this->actingAs($user)->get(paymentsUrl($property, '?group=month'))->assertSessionHasErrors('group');
    $this->actingAs($user)->get("/p/{$property->code}/reports/revenue?group=staff")->assertSessionHasErrors('group');
    $this->actingAs($user)->get("/p/{$property->code}/reports/revenue?group=month")->assertSessionHasNoErrors();
});

test('the Staff filter narrows the rows, the totals and both summaries', function () {
    [$user, $property] = paymentsSetup();
    $data = cashUpData($property);
    $query = '?from=2026-10-08&to=2026-10-10&staff='.$data['alice']->id;

    $this->actingAs($user)->get(paymentsUrl($property, $query))->assertInertia(fn (Assert $page) => $page
        ->where('staff', (string) $data['alice']->id)
        ->where('rows.meta.total', 5)
        ->where('totals.figures.amount', '330.00')
        ->where('breakdown.rows', [['Card', '2', '300.00', '-50.00', '250.00'], ['Cash', '1', '80.00', '0.00', '80.00']])
        ->where('breakdown.total', ['Total', '3', '380.00', '-50.00', '330.00']));

    $this->actingAs($user)->get(paymentsUrl($property, $query.'&group=staff'))->assertInertia(fn (Assert $page) => $page
        ->where('breakdown.subtotalRows', [2])
        ->where('breakdown.total', ['Total', '', '3', '380.00', '-50.00', '330.00']));
});

test('the Staff filter refuses a user of another property and lists the staff who took payments in the range', function () {
    [$user, $property] = paymentsSetup();
    [, $other] = paymentsSetup();
    $data = cashUpData($property);
    $foreign = User::factory()->create(['property_id' => $other->id]);
    User::factory()->create(['property_id' => $property->id, 'name' => 'Idle Staff']);
    $card = PaymentMethod::factory()->create(['property_id' => $property->id]);
    $early = User::factory()->create(['property_id' => $property->id, 'name' => 'Early Staff']);
    reportPayment($property, $card, '10.00', '2026-10-01', staff: $early);

    $this->actingAs($user)->get(paymentsUrl($property, '?staff='.$foreign->id))->assertSessionHasErrors('staff');
    $this->actingAs($user)->get(paymentsUrl($property, '?staff=abc'))->assertSessionHasErrors('staff');

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10&staff='.$data['bob']->id))->assertInertia(fn (Assert $page) => $page
        ->where('staffOptions', [
            ['value' => (string) $data['alice']->id, 'label' => 'Alice Cashier'],
            ['value' => (string) $data['bob']->id, 'label' => 'Bob Cashier'],
        ])
        ->where('rows.meta.total', 3));
});

test('Refunds only shows the refunds, leaves out city ledger rows and shows the original receipt', function () {
    [$user, $property] = paymentsSetup();
    $rows = cashUpData($property)['rows'];

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10&refunds=1'))->assertInertia(fn (Assert $page) => $page
        ->where('refundsOnly', true)
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.key', "p:{$rows['refund']->id}")
        ->where('rows.data.0.refund_of', $rows['paid']->receipt_number)
        ->where('totals.figures.amount', '-50.00'));

    $this->actingAs($user)->get(paymentsUrl($property, '?from=2026-10-08&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('refundsOnly', false)
        ->where('rows.data', fn ($list) => collect($list)->filter(fn ($row) => $row['refund_of'] !== null)->pluck('key')->all() === ["p:{$rows['refund']->id}"]));
});

test('refund_of is hidden by default and loads without extra queries per row', function () {
    [$user, $property] = paymentsSetup();
    cashUpData($property);

    $this->actingAs($user)->get(paymentsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('layout.columns', fn ($columns) => collect($columns)->firstWhere('key', 'refund_of')['visible'] === false
            && collect($columns)->firstWhere('key', 'refund_of')['label'] === 'Original receipt'));

    $property->refresh();
    $report = app(PaymentsReport::class);
    $filters = new ReportFilters('2026-10-08', '2026-10-10');
    DB::enableQueryLog();
    $report->rows($property, $filters);
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();
    reportPayment($property, PaymentMethod::factory()->create(['property_id' => $property->id]), '5.00', '2026-10-09');
    DB::flushQueryLog();
    $report->rows($property, $filters);
    $more = count(DB::getQueryLog());

    expect($more)->toBe($few);
});

test('the exports and the layout preview carry staff, refunds and group', function () {
    [$user, $property] = paymentsSetup();
    $data = cashUpData($property);
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/payments/pdf", [
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'group' => 'staff',
        'staff' => $data['alice']->id,
    ])->assertOk();

    expect($captured->data['groupCaption'])->toBe('By staff')
        ->and($captured->data['rows'])->toHaveCount(5)
        ->and($captured->data['breakdown']->subtotalRows)->toBe([2])
        ->and($captured->data['breakdown']->rows[2][0])->toBe('Alice Cashier subtotal');

    $this->actingAs($user)->post("/p/{$property->code}/reports/payments/pdf", [
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'refunds' => 1,
    ])->assertOk();

    expect($captured->data['rows'])->toHaveCount(1);

    $csv = $this->actingAs($user)->post("/p/{$property->code}/reports/payments/spreadsheet", [
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'format' => 'csv',
        'staff' => $data['bob']->id,
    ])->streamedContent();

    expect(array_filter(explode("\n", $csv)))->toHaveCount(5);

    $this->actingAs($user)->post("/p/{$property->code}/reports/payments/pdf", [
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'staff' => User::factory()->create()->id,
    ])->assertSessionHasErrors('staff');
});

test('the PDF prints the cash-up table with its subtotal rows in bold', function () {
    [$user, $property] = paymentsSetup();
    cashUpData($property);
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/payments/pdf", [
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'group' => 'staff',
    ])->assertOk();

    $html = view('pdf.report', $captured->data)->render();

    expect($html)->toContain('Cash-up by staff')
        ->and($html)->toContain('By staff')
        ->and($html)->toContain('<strong>Alice Cashier subtotal</strong>')
        ->and($html)->toContain('<strong>Bob Cashier subtotal</strong>')
        ->and($html)->not->toContain('<strong>Alice Cashier</strong>');
});
