<?php

require_once __DIR__.'/../Support/ManualFolioSetup.php';

use App\Actions\Folio\AddLineTax;
use App\Actions\Folio\ApplyDiscount;
use App\Actions\Folio\PostAdjustment;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostFolioCharge;
use App\Actions\Folio\RecalculateLineTax;
use App\Actions\Folio\RemoveLineTax;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Data\Folio\NewChargeData;
use App\Data\Reports\DailyChargeRow;
use App\Data\Reports\ReportFilters;
use App\Enums\ChargeBucket;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\ReportKey;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\DiscountQuote;
use App\Services\Folio\QuotedDiscount;
use App\Services\Reports\DailyChargesReport;
use App\Services\Reports\DailyRevenueReport;
use App\Services\Reports\ReportLayoutResolver;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The manual folio setup (a live property on 2026-10-13, an Owner, a checked-in unit, a master folio, SST 8% on the
 * Minibar item) plus a flat Tourism tax (TTX) and a Service charge 6% (SVC) that no code has, a Room, a Day use, a Fee
 * and a promo code, and a second open folio. SST and TTX apply to the Room code and the Minibar code.
 *
 * @return array<string, mixed>
 */
function dcSetup(bool $withTaxes = true): array
{
    $setup = mfSetup();
    $property = $setup['property'];
    $setup['sst']->forceFill(['code' => 'SST'])->save();
    $setup['ttx'] = Tax::factory()->for($property)->create(['name' => 'Tourism tax', 'code' => 'TTX', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00']);
    $setup['svc'] = Tax::factory()->for($property)->create(['name' => 'Service charge', 'code' => 'SVC', 'rate' => '6.00']);
    $setup['room'] = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $setup['dayUse'] = TransactionCode::factory()->system(SystemTransactionCode::DayUseRevenue)->create(['property_id' => $property->id]);
    $setup['fee'] = TransactionCode::factory()->create(['property_id' => $property->id, 'category' => TransactionCategory::Fee]);
    $setup['promo'] = TransactionCode::factory()->create(['property_id' => $property->id, 'kind' => TransactionKind::Adjustment, 'category' => TransactionCategory::Discount]);
    TransactionCode::factory()->system(SystemTransactionCode::Adjustment)->create(['property_id' => $property->id]);
    $setup['minibarCode'] = TransactionCode::query()->findOrFail($setup['minibar']->transaction_code_id);
    $setup['other'] = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $property->id, 'reservation_id' => $setup['reservation']->id]);

    foreach ([$setup['room'], $setup['minibarCode']] as $code) {
        $setup['ttx']->applicableTransactionCodes()->syncWithoutDetaching([$code->id]);
        $setup['sst']->applicableTransactionCodes()->syncWithoutDetaching([$code->id]);
    }

    if (! $withTaxes) {
        foreach (Tax::query()->where('property_id', $property->id)->get() as $tax) {
            $tax->applicableTransactionCodes()->detach();
            $tax->delete();
        }
    }

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 */
function dcOn(array $setup, string $date): void
{
    BusinessDate::query()->where('property_id', $setup['property']->id)->current()->update(['date' => $date]);
}

/**
 * Posts a charge on the business date with the code's own taxes, or with the taxes given.
 *
 * @param  array<string, mixed>  $setup
 * @param  list<int>|null  $taxIds
 */
function dcCharge(array $setup, TransactionCode $code, string $amount, ?Folio $folio = null, ?array $taxIds = null, ?string $description = null): FolioLine
{
    return app(PostCharge::class)($setup['user'], $setup['unit'], new NewChargeData(
        chargeItemId: null,
        transactionCodeId: $code->id,
        description: $description,
        quantity: 1,
        unitAmount: $amount,
        idempotencyKey: 'manual:'.fake()->uuid(),
        folioId: ($folio ?? $setup['folio'])->id,
        taxIds: $taxIds,
    ))->line();
}

/**
 * A charge with an inline discount, as the night posting writes it: the discount is an Adjustment child beside the taxes.
 *
 * @param  array<string, mixed>  $setup
 * @return non-empty-list<FolioLine>
 */
function dcInline(array $setup, TransactionCode $code, string $amount, string $discount, string $date): array
{
    $currency = $setup['property']->settings->currency->getCurrencyCode();
    $money = Money::of($amount, $currency);
    $key = 'inline:'.fake()->uuid();

    return app(PostFolioCharge::class)(
        $setup['user'],
        $setup['folio'],
        $code,
        'Room night',
        1,
        $money,
        app(TaxCalculator::class)->calculate($setup['property'], $code, $money, '1', 'MY'),
        $key,
        $date,
        discounts: [new QuotedDiscount($key.':discount', 'Promo rate', Money::of($discount, $currency), $setup['promo']->id)],
    );
}

/**
 * @param  array<string, mixed>  $setup
 */
function dcDiscount(array $setup, Folio $folio, string $value = '10.00'): void
{
    $discount = Discount::factory()->for($setup['property'])->create(['scope' => DiscountScope::AllCharges, 'value' => $value]);
    $date = BusinessDate::query()->where('property_id', $setup['property']->id)->current()->firstOrFail()->date;
    $quote = app(DiscountQuote::class)->quote($folio->refresh(), $discount->refresh(), CarbonImmutable::parse($date->toDateString()));

    expect(app(ApplyDiscount::class)($setup['user'], $folio, $discount, null, $quote->fingerprint)->isRefused())->toBeFalse();
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $filters
 * @return list<DailyChargeRow>
 */
function dcRows(array $setup, string $from, string $to, array $filters = []): array
{
    return app(DailyChargesReport::class)->rows($setup['property'], new ReportFilters($from, $to, ...$filters));
}

/**
 * The columns that matter in a short form: date, charge, base, discount, SST, TTX, SVC, total, status.
 *
 * @param  list<DailyChargeRow>  $rows
 * @return list<array<int, string>>
 */
function dcSummary(array $rows): array
{
    return array_map(fn (DailyChargeRow $row): array => [
        $row->date, $row->charge, $row->base, $row->discount, $row->taxes['SST'] ?? '-', $row->taxes['TTX'] ?? '-', $row->taxes['SVC'] ?? '-', $row->total, $row->status->value,
    ], $rows);
}

/**
 * @param  array<string, mixed>  $setup
 */
function dcUrl(array $setup, string $query = ''): string
{
    return "/p/{$setup['property']->code}/reports/daily-report?tab=charges".$query;
}

test('a line gets its bucket from its kind, category and system code', function (TransactionKind $kind, string $category, ?string $systemKey, ?ChargeBucket $bucket) {
    expect(ChargeBucket::forLine($kind, $category, $systemKey))->toBe($bucket);
})->with([
    'room' => [TransactionKind::Charge, 'room', 'room_revenue', ChargeBucket::Accommodation],
    'no-show room' => [TransactionKind::Charge, 'room', 'no_show_revenue', ChargeBucket::Accommodation],
    'day use' => [TransactionKind::Charge, 'room', 'day_use_revenue', ChargeBucket::DayUse],
    'fee' => [TransactionKind::Charge, 'fee', null, ChargeBucket::Fees],
    'minibar' => [TransactionKind::Charge, 'minibar', null, ChargeBucket::Extras],
    'unmapped' => [TransactionKind::Charge, 'other', null, ChargeBucket::Extras],
    'discount' => [TransactionKind::Adjustment, 'discount', null, ChargeBucket::Adjustments],
    'adjustment' => [TransactionKind::Adjustment, 'adjustment', 'adjustment', ChargeBucket::Adjustments],
    'tax' => [TransactionKind::Tax, 'tax', null, null],
    'payment' => [TransactionKind::Payment, 'cash', null, null],
]);

test('a charge is one row with its taxes and a bucket from its code', function () {
    $setup = dcSetup();
    dcCharge($setup, $setup['room'], '100.00');
    dcCharge($setup, $setup['fee'], '30.00');
    dcCharge($setup, $setup['dayUse'], '40.00');

    $rows = dcRows($setup, '2026-10-13', '2026-10-13');

    expect(dcSummary($rows))->toBe([
        ['2026-10-13', 'Room revenue', '100.00', '0.00', '8.00', '10.00', '0.00', '118.00', 'posted'],
        ['2026-10-13', $setup['fee']->name, '30.00', '0.00', '0.00', '0.00', '0.00', '30.00', 'posted'],
        ['2026-10-13', 'Day use', '40.00', '0.00', '0.00', '0.00', '0.00', '40.00', 'posted'],
    ])
        ->and(array_map(fn (DailyChargeRow $row): string => $row->bucket, $rows))->toBe(['Accommodation', 'Fees', 'Day use'])
        ->and($rows[0]->booking)->toBe($setup['reservation']->reference)
        ->and($rows[0]->guest)->toBe($setup['reservation']->booker->full_name)
        ->and($rows[0]->folio)->toBe($setup['folio']->number)
        ->and($rows[0]->code)->toBe($setup['room']->code)
        ->and($rows[0]->staff)->toBe($setup['user']->name);
});

test('an inline discount is in the Discount column of its charge, with and without taxes', function () {
    $setup = dcSetup();
    dcInline($setup, $setup['room'], '100.00', '20.00', '2026-10-13');
    dcInline($setup, $setup['fee'], '50.00', '5.00', '2026-10-13');

    expect(dcSummary(dcRows($setup, '2026-10-13', '2026-10-13')))->toBe([
        ['2026-10-13', 'Room night', '100.00', '-20.00', '8.00', '10.00', '0.00', '98.00', 'posted'],
        ['2026-10-13', 'Room night', '50.00', '-5.00', '0.00', '0.00', '0.00', '45.00', 'posted'],
    ]);
});

test('a discount posted later with taxes is a row of its own in Adjustments', function () {
    $setup = dcSetup();
    $charge = dcCharge($setup, $setup['room'], '100.00');
    dcOn($setup, '2026-10-14');
    dcDiscount($setup, $setup['folio']);

    $rows = dcRows($setup, '2026-10-13', '2026-10-14');

    expect(dcSummary($rows))->toBe([
        ['2026-10-13', 'Room revenue', '100.00', '0.00', '8.00', '10.00', '0.00', '118.00', 'posted'],
        ['2026-10-14', Discount::query()->firstOrFail()->name, '-10.00', '0.00', '-0.80', '0.00', '0.00', '-10.80', 'posted'],
    ])
        ->and($rows[1]->bucket)->toBe('Adjustments')
        ->and($rows[1]->key)->toBe(FolioLine::query()->where('kind', TransactionKind::Adjustment)->where('parent_line_id', $charge->id)->value('id'));
});

test('a discount posted later with no taxes is the Discount of a row of its charge with a zero base', function () {
    $setup = dcSetup();
    $charge = dcCharge($setup, $setup['fee'], '100.00', $setup['other'], description: 'Late fee');
    dcOn($setup, '2026-10-14');
    dcDiscount($setup, $setup['other']);

    $rows = dcRows($setup, '2026-10-14', '2026-10-14');

    expect(dcSummary($rows))->toBe([['2026-10-14', 'Late fee', '0.00', '-10.00', '0.00', '0.00', '0.00', '-10.00', 'posted']])
        ->and($rows[0]->bucket)->toBe('Fees')
        ->and($rows[0]->key)->toBe(FolioLine::query()->where('parent_line_id', $charge->id)->value('id'));
});

test('a tax removed, recalculated or added on a later date is a row of its charge with a zero base', function () {
    $setup = dcSetup();
    $charge = dcCharge($setup, $setup['minibarCode'], '100.00', description: 'Minibar');
    $sstLine = $charge->children()->where('transaction_code_id', $setup['sst']->transaction_code_id)->firstOrFail();

    dcOn($setup, '2026-10-14');
    expect(app(RemoveLineTax::class)($setup['user'], $charge, [$sstLine->id], 'Guest is exempt', 'dc-remove')->isRefused())->toBeFalse();
    dcOn($setup, '2026-10-15');
    expect(app(RecalculateLineTax::class)($setup['user'], $charge, 'Fixing a mistake', 'dc-recalc')->isRefused())->toBeFalse();
    dcOn($setup, '2026-10-16');
    expect(app(AddLineTax::class)($setup['user'], $charge, [$setup['svc']->id], 'Tax was missed', 'dc-add')->isRefused())->toBeFalse();

    $rows = dcRows($setup, '2026-10-13', '2026-10-16');

    expect(dcSummary($rows))->toBe([
        ['2026-10-13', 'Minibar', '100.00', '0.00', '8.00', '10.00', '0.00', '118.00', 'posted'],
        ['2026-10-14', 'Minibar', '0.00', '0.00', '-8.00', '0.00', '0.00', '-8.00', 'reversal'],
        ['2026-10-15', 'Minibar', '0.00', '0.00', '8.00', '0.00', '0.00', '8.00', 'reversal'],
        ['2026-10-16', 'Minibar', '0.00', '0.00', '0.00', '0.00', '6.00', '6.00', 'posted'],
    ])
        ->and(array_map(fn (DailyChargeRow $row): string => $row->bucket, $rows))->toBe(['Extras', 'Extras', 'Extras', 'Extras']);
});

test('a reversal is a negative row of its own that folds in the reversal of the discount and taxes', function () {
    $setup = dcSetup();
    $lines = dcInline($setup, $setup['room'], '100.00', '20.00', '2026-10-13');
    dcOn($setup, '2026-10-14');
    expect(app(ReverseLine::class)($setup['user'], $lines[0])->isRefused())->toBeFalse();

    $rows = dcRows($setup, '2026-10-14', '2026-10-14');

    expect(dcSummary($rows))->toBe([['2026-10-14', 'Room night', '-100.00', '20.00', '-8.00', '-10.00', '0.00', '-98.00', 'reversal']])
        ->and($rows[0]->key)->toBe(FolioLine::query()->where('reversal_of_id', $lines[0]->id)->value('id'));
});

test('a moved charge shows the reversal and the copy as Moved and nets to zero', function () {
    $setup = dcSetup();
    $charge = dcCharge($setup, $setup['room'], '100.00');
    dcOn($setup, '2026-10-14');
    expect(app(TransferLines::class)($setup['user'], [$charge], $setup['other'])->isRefused())->toBeFalse();

    $rows = dcRows($setup, '2026-10-14', '2026-10-14');
    $totals = app(DailyChargesReport::class)->totals($rows);

    expect(dcSummary($rows))->toBe([
        ['2026-10-14', 'Room revenue', '100.00', '0.00', '8.00', '10.00', '0.00', '118.00', 'moved'],
        ['2026-10-14', 'Room revenue', '-100.00', '0.00', '-8.00', '-10.00', '0.00', '-118.00', 'moved'],
    ])
        ->and(array_map(fn (DailyChargeRow $row): string => $row->folio, $rows))->toBe([$setup['other']->number, $setup['folio']->number])
        ->and($totals->figures['total'])->toBe('0.00')
        ->and($totals->figures['base'])->toBe('0.00');
});

test('a top-level adjustment is its own row in Adjustments, and payments, migrated lines and the opening balance are left out', function () {
    $setup = dcSetup();
    app(PostAdjustment::class)($setup['user'], $setup['folio'], '-5.00', 'Goodwill', 'dc-adjust');
    dcCharge($setup, $setup['fee'], '30.00');
    $opening = TransactionCode::factory()->system(SystemTransactionCode::OpeningBalance)->create(['property_id' => $setup['property']->id]);

    foreach ([[$setup['fee'], true, TransactionKind::Charge], [$opening, false, TransactionKind::Charge], [$setup['fee'], false, TransactionKind::Payment]] as [$code, $migrated, $kind]) {
        FolioLine::factory()->create(['folio_id' => $setup['folio']->id, 'transaction_code_id' => $code->id, 'kind' => $kind, 'amount' => '77.00', 'unit_amount' => '77.00', 'posting_date' => '2026-10-13', 'migrated' => $migrated]);
    }

    $rows = dcRows($setup, '2026-10-13', '2026-10-13');

    expect(dcSummary($rows))->toBe([
        ['2026-10-13', 'Goodwill', '-5.00', '0.00', '0.00', '0.00', '0.00', '-5.00', 'posted'],
        ['2026-10-13', $setup['fee']->name, '30.00', '0.00', '0.00', '0.00', '0.00', '30.00', 'posted'],
    ])
        ->and($rows[0]->bucket)->toBe('Adjustments');
});

test('the totals equal the Revenue tab for the same dates across a mixed scenario', function () {
    $setup = dcSetup();
    $property = $setup['property'];

    $room = dcCharge($setup, $setup['room'], '100.00');
    dcInline($setup, $setup['room'], '100.00', '20.00', '2026-10-13');
    $minibar = dcCharge($setup, $setup['minibarCode'], '50.00', $setup['other']);
    dcCharge($setup, $setup['fee'], '30.00');
    dcCharge($setup, $setup['dayUse'], '40.00');
    app(PostAdjustment::class)($setup['user'], $setup['folio'], '-5.00', 'Goodwill', 'dc-adjust');

    dcOn($setup, '2026-10-14');
    $sstLine = $minibar->children()->where('transaction_code_id', $setup['sst']->transaction_code_id)->firstOrFail();
    app(RemoveLineTax::class)($setup['user'], $minibar, [$sstLine->id], 'Guest is exempt', 'dc-remove');
    app(TransferLines::class)($setup['user'], [$room], $setup['other']);
    $fee = dcCharge($setup, $setup['fee'], '12.00');
    app(ReverseLine::class)($setup['user'], $fee);

    dcOn($setup, '2026-10-15');
    app(RecalculateLineTax::class)($setup['user'], $minibar, 'Fixing a mistake', 'dc-recalc');
    $copy = FolioLine::query()->where('folio_id', $setup['other']->id)->whereNull('reversal_of_id')->where('kind', TransactionKind::Charge)->firstOrFail();
    app(AddLineTax::class)($setup['user'], $copy, [$setup['svc']->id], 'Tax was missed', 'dc-add');
    dcDiscount($setup, $setup['folio']);

    dcOn($setup, '2026-10-16');
    $inline = FolioLine::query()->where('folio_id', $setup['folio']->id)->whereNull('parent_line_id')->whereNull('reversal_of_id')->where('description', 'Room night')->firstOrFail();
    app(ReverseLine::class)($setup['user'], $inline);

    $filters = new ReportFilters('2026-10-13', '2026-10-16');
    $charges = app(DailyChargesReport::class);
    $revenue = app(DailyRevenueReport::class);
    $chargeRows = $charges->rows($property, $filters);
    $revenueRows = $revenue->rows($property, $filters);
    $chargeTotals = $charges->totals($chargeRows)->figures;
    $revenueTotals = $revenue->totals($revenueRows)->figures;

    expect($chargeTotals['total'])->toBe($revenueTotals['total'])
        ->and($chargeTotals['tax:SST'])->toBe($revenueTotals['tax:SST'])
        ->and($chargeTotals['tax:TTX'])->toBe($revenueTotals['tax:TTX'])
        ->and($chargeTotals['tax:SVC'])->toBe($revenueTotals['tax:SVC'])
        ->and(count($chargeRows))->toBeGreaterThan(10);

    foreach ($revenueRows as $revenueRow) {
        $day = array_values(array_filter($chargeRows, fn (DailyChargeRow $row): bool => $row->date === $revenueRow->period));
        $sum = fn (callable $amount): string => number_format(array_sum(array_map($amount, $day)), 2, '.', '');

        expect($sum(fn (DailyChargeRow $row): float => (float) $row->total))->toBe($revenueRow->total, $revenueRow->period)
            ->and($sum(fn (DailyChargeRow $row): float => (float) $row->taxes['SST']))->toBe($revenueRow->taxes['SST'], $revenueRow->period)
            ->and($sum(fn (DailyChargeRow $row): float => (float) $row->taxes['TTX']))->toBe($revenueRow->taxes['TTX'], $revenueRow->period)
            ->and($sum(fn (DailyChargeRow $row): float => (float) $row->taxes['SVC']))->toBe($revenueRow->taxes['SVC'], $revenueRow->period);
    }

    $adjustmentBases = array_sum(array_map(fn (DailyChargeRow $row): float => $row->bucket === 'Adjustments' ? (float) $row->base : 0.0, $chargeRows));
    $chargeBases = array_sum(array_map(fn (DailyChargeRow $row): float => $row->bucket === 'Adjustments' ? 0.0 : (float) $row->base, $chargeRows));

    expect(number_format($adjustmentBases + (float) $chargeTotals['discount'], 2, '.', ''))->toBe($revenueTotals['adjustments'])
        ->and(number_format($chargeBases, 2, '.', ''))->toBe(number_format((float) $revenueTotals['accommodation'] + (float) $revenueTotals['day_use'] + (float) $revenueTotals['fees'] + (float) $revenueTotals['extras'], 2, '.', ''));
});

test('the bucket filter narrows the rows and the totals, and the search covers the booking and the guest', function () {
    $setup = dcSetup();
    dcCharge($setup, $setup['room'], '100.00');
    dcCharge($setup, $setup['fee'], '30.00');
    dcCharge($setup, $setup['fee'], '20.00', $setup['other']);
    $reference = $setup['reservation']->reference;
    $guest = $setup['reservation']->booker->full_name;
    $filteredByFee = dcRows($setup, '2026-10-13', '2026-10-13', ['bucket' => ChargeBucket::Fees]);

    expect(array_column(dcSummary($filteredByFee), 7))->toBe(['30.00', '20.00'])
        ->and(app(DailyChargesReport::class)->totals($filteredByFee)->figures['total'])->toBe('50.00')
        ->and(dcRows($setup, '2026-10-13', '2026-10-13', ['bucket' => ChargeBucket::DayUse]))->toBe([])
        ->and(dcRows($setup, '2026-10-13', '2026-10-13', ['search' => strtolower(substr($reference, 1, 4))]))->toHaveCount(3)
        ->and(dcRows($setup, '2026-10-13', '2026-10-13', ['search' => strtoupper($guest)]))->toHaveCount(3)
        ->and(dcRows($setup, '2026-10-13', '2026-10-13', ['search' => '%']))->toBe([])
        ->and(dcRows($setup, '2026-10-13', '2026-10-13', ['search' => '_']))->toBe([])
        ->and(dcRows($setup, '2026-10-13', '2026-10-13', ['search' => 'no such guest']))->toBe([])
        ->and(dcRows($setup, '2026-10-13', '2026-10-13', ['bucket' => ChargeBucket::Fees, 'search' => 'no such guest']))->toBe([]);
});

test('the page sends the rows, the totals and the filters, and keeps them in the URL', function () {
    $setup = dcSetup();
    dcCharge($setup, $setup['room'], '100.00');
    dcCharge($setup, $setup['fee'], '30.00');
    $reference = $setup['reservation']->reference;

    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/daily-report')
        ->where('tab', 'charges')
        ->where('maxRangeDays', 31)
        ->where('sort', 'date')
        ->where('bucket', null)
        ->where('search', '')
        ->where('bucketOptions', fn ($options) => collect($options)->pluck('value')->all() === ['accommodation', 'day-use', 'fees', 'extras', 'adjustments'])
        ->where('layout.columns', fn ($columns) => collect($columns)->pluck('key')->all() === ['date', 'charge', 'booking', 'guest', 'base', 'discount', 'tax:SST', 'tax:TTX', 'tax:SVC', 'total', 'bucket', 'folio', 'room', 'code', 'status', 'staff'])
        ->where('layout.columns', fn ($columns) => collect($columns)->where('visible', true)->pluck('key')->all() === ['date', 'charge', 'booking', 'guest', 'base', 'discount', 'tax:SST', 'tax:TTX', 'tax:SVC', 'total'])
        ->where('rows.meta.total', 2)
        ->where('rows.data.0.status.label', 'Posted')
        ->where('totals.figures.base', '130.00')
        ->where('totals.figures.tax:SST', '8.00')
        ->where('totals.figures.total', '148.00'));

    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13&bucket=fees&search='.urlencode(substr($reference, 0, 3))))->assertInertia(fn (Assert $page) => $page
        ->where('bucket', 'fees')
        ->where('search', substr($reference, 0, 3))
        ->where('rows.meta.total', 1)
        ->where('totals.figures.total', '30.00'));

    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13&bucket=nope'))->assertSessionHasErrors('bucket');
    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13&search='.str_repeat('a', 101)))->assertSessionHasErrors('search');
});

test('rows sort by a tax column', function () {
    $setup = dcSetup();
    dcCharge($setup, $setup['fee'], '30.00');
    dcCharge($setup, $setup['room'], '100.00');

    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13&sort=-tax:SST'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', '-tax:SST')
        ->where('rows.data.0.charge', 'Room revenue'));
});

test('the tab takes 31 days at most and the tab link and the drill-down cut a longer range', function () {
    $setup = dcSetup();
    dcOn($setup, '2026-09-25');
    dcCharge($setup, $setup['fee'], '10.00');
    dcOn($setup, '2026-10-13');
    dcCharge($setup, $setup['room'], '100.00');

    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-09-12&to=2026-10-13'))->assertSessionHasErrors('to');
    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-09-13&to=2026-10-13'))->assertSessionDoesntHaveErrors();

    $this->actingAs($setup['user'])->get("/p/{$setup['property']->code}/reports/daily-report?tab=revenue&from=2026-09-01&to=2026-10-13")->assertInertia(fn (Assert $page) => $page
        ->where('tabs.2.value', 'charges')
        ->where('tabs.2.href', "/p/{$setup['property']->code}/reports/daily-report?tab=charges&from=2026-09-13&to=2026-10-13")
        ->where('tabs.1.href', "/p/{$setup['property']->code}/reports/daily-report?tab=revenue&from=2026-09-01&to=2026-10-13")
        ->where('rows.data.1.from', '2026-10-13')
        ->where('rows.data.1.to', '2026-10-13'));

    $this->actingAs($setup['user'])->get("/p/{$setup['property']->code}/reports/daily-report?tab=revenue&from=2026-09-20&to=2026-10-13&group=month")->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.from', '2026-09-20')
        ->where('rows.data.0.to', '2026-09-30')
        ->where('rows.data.1.from', '2026-10-01')
        ->where('rows.data.1.to', '2026-10-13'));

    $filters = new ReportFilters('2026-09-01', '2026-10-13');

    expect($filters->clampedFrom(31))->toBe('2026-09-13')
        ->and($filters->clampedFrom(366))->toBe('2026-09-01');
});

test('the tax columns go before Total in the default and in a saved layout', function () {
    $setup = dcSetup();
    $property = $setup['property'];
    $keys = fn (): array => array_map(fn ($column): string => $column->key, app(ReportLayoutResolver::class)->resolve($property, ReportKey::DailyCharges)->columns);
    $layout = ReportLayout::factory()->create(['property_id' => $property->id, 'report_key' => ReportKey::DailyCharges->value]);

    foreach (['total', 'date', 'base'] as $position => $key) {
        ReportLayoutColumn::factory()->create(['report_layout_id' => $layout->id, 'column_key' => $key, 'position' => $position]);
    }

    expect(ReportKey::DailyCharges->taxColumnAnchor())->toBe('total')
        ->and(ReportKey::DailyRevenue->taxColumnAnchor())->toBe('adjustments')
        ->and(array_slice($keys(), 0, 6))->toBe(['tax:SST', 'tax:TTX', 'tax:SVC', 'total', 'date', 'base']);
});

test('a property with no tax has no tax column and the totals still add up', function () {
    $setup = dcSetup(withTaxes: false);
    dcCharge($setup, $setup['room'], '100.00');

    expect(array_map(fn ($column): string => $column->key, ReportKey::DailyCharges->columnsFor($setup['property'])))
        ->toBe(['date', 'charge', 'booking', 'guest', 'base', 'discount', 'total', 'bucket', 'folio', 'room', 'code', 'status', 'staff']);

    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.total', '100.00')
        ->where('totals.figures.total', '100.00'));
});

test('the PDF and the CSV keep the filters', function () {
    $setup = dcSetup();
    dcCharge($setup, $setup['room'], '100.00');
    dcCharge($setup, $setup['fee'], '30.00', description: 'Late fee');
    $base = ['from' => '2026-10-13', 'to' => '2026-10-13', 'bucket' => 'fees', 'search' => substr($setup['reservation']->reference, 0, 3)];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($setup['user'])->post("/p/{$setup['property']->code}/reports/daily-charges/pdf", $base)->assertOk();
    expect($captured->data['rows'])->toHaveCount(1);

    $response = $this->actingAs($setup['user'])->post("/p/{$setup['property']->code}/reports/daily-charges/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows[0])->toBe(['Date', 'Charge', 'Booking', 'Guest / payer', 'Base', 'Discount', 'SST', 'Tourism tax', 'Service charge', 'Total'])
        ->and($rows)->toHaveCount(3)
        ->and($rows[1][1])->toBe('Late fee')
        ->and(array_slice($rows[1], 4))->toBe(['30', '0', '0', '0', '0', '30'])
        ->and(end($rows))->toContain('Total')
        ->and(end($rows)[9])->toBe('30');

    $this->actingAs($setup['user'])->post("/p/{$setup['property']->code}/reports/daily-charges/spreadsheet", ['from' => '2026-10-13', 'to' => '2026-10-13', 'bucket' => 'nope', 'format' => 'csv'])->assertSessionHasErrors('bucket');
});

test('the page needs reports.view and shows only the lines of its property', function () {
    $setup = dcSetup();
    dcCharge($setup, $setup['room'], '100.00');
    $front = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $otherProperty = Property::factory()->create();

    $this->actingAs($front)->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13'))->assertForbidden();
    $this->actingAs($setup['user'])->get("/p/{$otherProperty->code}/reports/daily-report?tab=charges")->assertNotFound();

    $strange = dcSetup();
    dcCharge($strange, $strange['fee'], '999.00');

    $this->actingAs($setup['user'])->get(dcUrl($setup, '&from=2026-10-13&to=2026-10-13'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 1)
        ->where('totals.figures.total', '118.00'));
});

test('the number of queries does not depend on the number of lines, with lazy loading prevented', function () {
    $setup = dcSetup();
    $shape = function () use ($setup): void {
        $charge = dcCharge($setup, $setup['minibarCode'], '100.00');
        dcInline($setup, $setup['room'], '100.00', '10.00', '2026-10-13');
        $sstLine = $charge->children()->where('transaction_code_id', $setup['sst']->transaction_code_id)->firstOrFail();
        app(RemoveLineTax::class)($setup['user'], $charge, [$sstLine->id], 'Guest is exempt', 'dc-remove-'.fake()->uuid());
        app(ReverseLine::class)($setup['user'], dcCharge($setup, $setup['fee'], '5.00', $setup['other']));
    };
    $count = function () use ($setup): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        dcRows($setup, '2026-10-13', '2026-10-13');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $shape();
    dcDiscount($setup, $setup['folio']);
    Model::preventLazyLoading();

    try {
        dcRows($setup, '2026-10-13', '2026-10-13');
        $few = $count();

        foreach (range(1, 3) as $ignored) {
            $shape();
        }

        $many = $count();
        $rows = count(dcRows($setup, '2026-10-13', '2026-10-13'));
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($rows)->toBeGreaterThan(12)
        ->and($many)->toBe($few)
        ->and($few)->toBeLessThanOrEqual(20);
});
