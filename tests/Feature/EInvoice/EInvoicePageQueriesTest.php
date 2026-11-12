<?php

require_once __DIR__.'/../Support/EInvoiceSetup.php';

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Enums\TransactionKind;
use App\Jobs\MonthlyConsolidationJob;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Services\EInvoice\SummaryMonth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Queries one GET runs, with lazy loading turned off so a missing eager load fails the test.
 */
function eInvoicePageQueries(array $setup, string $url): int
{
    Model::preventLazyLoading();
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        test()->actingAs($setup['owner'])->get($url)->assertOk();

        return count(DB::getQueryLog());
    } finally {
        // Always back on, or a failed page would leave lazy loading off for the tests after it.
        DB::disableQueryLog();
        Model::preventLazyLoading(false);
    }
}

/**
 * A closed folio of RM100 on a new reservation of the setup property.
 */
function eInvoicePageFolio(array $setup): Folio
{
    $reservation = Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $setup['folio']->payer_guest_id]);
    $folio = Folio::factory()->for($setup['property'])->closed()->create(['reservation_id' => $reservation->id, 'payer_guest_id' => $setup['folio']->payer_guest_id]);
    FolioLine::factory()->for($folio)->create(['kind' => TransactionKind::Charge, 'amount' => '100.00', 'unit_amount' => '100.00']);

    return $folio;
}

it('runs the same queries on the e-invoice list for few and many rejected rows', function () {
    $setup = submissionSetup();

    $add = function (int $rows) use ($setup): void {
        foreach (range(1, $rows) as $ignored) {
            $folio = eInvoicePageFolio($setup);
            $rejected = EInvoiceSubmission::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $folio->id, 'reservation_id' => $folio->reservation_id, 'status' => EInvoiceStatus::Invalid]);
            EInvoiceSubmission::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $folio->id, 'reservation_id' => $folio->reservation_id, 'status' => EInvoiceStatus::Valid]);
            EInvoiceSubmission::factory()->create(['property_id' => $setup['property']->id, 'original_submission_id' => $rejected->id, 'document_type' => EInvoiceDocumentType::CreditNote, 'status' => EInvoiceStatus::Invalid]);
        }
    };
    $url = "/p/{$setup['property']->code}/finance/e-invoices";

    $add(1);
    eInvoicePageQueries($setup, $url);
    $add(1);
    $few = eInvoicePageQueries($setup, $url);
    $add(6);
    $many = eInvoicePageQueries($setup, $url);

    expect($many)->toBe($few);
});

it('runs the same queries on a monthly summary page for few and many stays', function () {
    $setup = submissionSetup();
    $queries = function (int $stays) use ($setup): int {
        $summary = EInvoiceSubmission::factory()->create(['property_id' => $setup['property']->id, 'is_consolidated' => true, 'status' => EInvoiceStatus::Valid]);
        $summary->folios()->attach(collect(range(1, $stays))->map(fn () => eInvoicePageFolio($setup)->id)->all());

        return eInvoicePageQueries($setup, "/p/{$setup['property']->code}/finance/e-invoices/{$summary->id}");
    };

    $queries(1);
    $few = $queries(2);
    $many = $queries(8);

    expect($many)->toBe($few);
});

it('runs the monthly summary job for several hotels without lazy loading', function () {
    // Laravel only stops a lazy load on a model read with others, so it takes two hotels.
    foreach ([submissionSetup(), submissionSetup()] as $setup) {
        $setup['setting']->update(['consolidation_enabled' => true]);
        $setup['folio']->payerGuest->update(['tin' => null]);
        $setup['folio']->update(['closed_at' => CarbonImmutable::parse('2026-09-15 10:00', 'UTC')]);
    }

    Model::preventLazyLoading();

    try {
        // The job reports and skips a hotel that fails, so a lazy load shows as no summary.
        (new MonthlyConsolidationJob(targetMonth: '2026-09-01'))->handle(app(ConsolidateMonthlyEInvoices::class), app(SummaryMonth::class));
    } finally {
        Model::preventLazyLoading(false);
    }

    expect(EInvoiceSubmission::query()->where('is_consolidated', true)->count())->toBe(2);
});
