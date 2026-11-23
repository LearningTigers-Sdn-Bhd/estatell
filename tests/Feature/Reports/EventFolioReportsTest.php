<?php

use App\Data\Reports\ReportFilters;
use App\Enums\ReportKey;
use App\Models\FolioLine;
use App\Models\Payment;

require_once __DIR__.'/../Support/EventSetup.php';

test('event folios show their event number, payer, status and dates in the finance reports', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$paid, $paidFolio] = makeEvent($property, $owner, ['starts_on' => '2026-12-11', 'ends_on' => '2026-12-12']);
    [$owing, $owingFolio] = makeEvent($property, $owner, ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-20']);
    Payment::factory()->create(['property_id' => $property->id, 'folio_id' => $paidFolio->id, 'amount' => '2000.00']);
    FolioLine::factory()->create(['folio_id' => $owingFolio->id, 'amount' => '500.00', 'posting_date' => '2026-11-02']);
    $range = new ReportFilters('2026-11-01', '2026-11-30');
    $onDate = new ReportFilters('2026-11-02', '2026-11-02');

    $payment = ReportKey::Payments->report()->rows($property, $range)[0];
    $ledger = collect(ReportKey::FolioLedger->report()->rows($property, $range))->keyBy('folio');
    $deposit = ReportKey::DepositLiability->report()->rows($property, $onDate)[0];
    $outstanding = ReportKey::OutstandingBalance->report()->rows($property, $onDate)[0];

    expect($payment->payer)->toBe($paid->number)
        ->and($payment->guest)->toBe($paid->payerGuest->full_name)
        ->and($ledger[$paidFolio->number]->reservation)->toBe($paid->number)
        ->and($ledger[$owingFolio->number]->reservation)->toBe($owing->number)
        ->and($deposit->reservation)->toBe($paid->number)
        ->and($deposit->arrival)->toBe('2026-12-11')
        ->and($deposit->departure)->toBe('2026-12-12')
        ->and($deposit->status->label)->toBe('Confirmed')
        ->and($outstanding->reservation)->toBe($owing->number)
        ->and($outstanding->payer)->toBe($owing->payerGuest->full_name);
});
