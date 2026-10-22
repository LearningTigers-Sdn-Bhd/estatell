<?php

use App\Enums\PaymentPurpose;
use App\Models\Folio;
use App\Models\Payment;
use App\Services\Onboarding\Wastays\WastaysDayCompare;

it('leaves security deposits out of the payment total, as wastays does', function () {
    $folio = Folio::factory()->create();
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '120.00']);
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '50.00', 'purpose' => PaymentPurpose::SecurityDeposit]);

    $figures = app(WastaysDayCompare::class)->estatell($folio->property, '2026-11-02');

    expect($figures['payments_total'])->toBe('120.00');
});
