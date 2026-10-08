<?php

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
function reportPayment(Property $property, PaymentMethod $method, string $amount, string $date, PaymentStatus $status = PaymentStatus::Completed, ?Payment $refundOf = null): Payment
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
        ->has('layout.columns', 8)
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
        ->where('breakdown.headings', ['Method', 'Count', 'Amount'])
        ->where('breakdown.rows', [['Card', '2', '250.00'], ['Cash', '2', '580.00']])
        ->where('breakdown.total', ['Total', '4', '830.00']));
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
        ->and($captured->data['breakdown']->rows)->toBe([['Card', '2', '250.00'], ['Cash', '1', '500.00']])
        ->and($captured->data['breakdown']->total)->toBe(['Total', '3', '750.00']);
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

test('the sidebar lists Payments and Folio ledger under the Finance category', function () {
    [$user, $property] = paymentsSetup();

    $this->actingAs($user)->get(paymentsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.contexts.2.groups', fn ($groups) => collect(collect($groups)->firstWhere('label', 'Finance')['items'])->pluck('title')->all() === ['Payments', 'Folio ledger']));
});
