<?php

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Actions\EInvoice\SubmitPosWalkInEInvoice;
use App\Actions\Pos\RefundPosOrder;
use App\Actions\Pos\SettlePosOrder;
use App\Data\Pos\PosRefundLineInputData;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Features\EInvoicing;
use App\Models\EInvoiceSubmission;
use App\Models\PosItemVariant;
use App\Models\PosOrder;
use App\Models\PropertyEInvoiceSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosOrderSetup.php';

function posLhdnSetup(): array
{
    $s = posOrderSetup();
    Feature::for($s['property'])->activate(EInvoicing::class);
    PropertyEInvoiceSetting::factory()->for($s['property'])->create([
        'enabled' => true,
        'api_environment' => EInvoiceEnvironment::Mock,
        'client_id' => 'mock-client-id',
        'client_secret' => 'mock-client-secret',
        'supplier_name' => 'Mock Grand Hotel Sdn Bhd',
        'supplier_tin' => 'C1234567890',
        'supplier_sst_registration' => 'W10-1808-12345678',
        'supplier_msic_code' => '55101',
        'supplier_business_description' => 'Hotel operations',
        'supplier_email' => 'accounts@hotel.my',
        'supplier_city' => 'Kuala Lumpur',
        'consolidation_enabled' => true,
    ]);

    return $s;
}

function posLhdnSale(array $s, PosItemVariant $variant, int $quantity = 1): PosOrder
{
    $order = posNewOrder($s);
    posAddLine($s, $order, $variant, $quantity);

    return app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($order->fresh()->grand_total)])->model;
}

function posLhdnConsolidate(array $s): array
{
    return app(ConsolidateMonthlyEInvoices::class)($s['property']->fresh(), CarbonImmutable::parse('2026-10-01'));
}

it('puts a walk-in sale on the monthly summary and marks the order', function () {
    $s = posLhdnSetup();
    $order = posLhdnSale($s, $s['steak']);

    [$submission] = posLhdnConsolidate($s);

    expect($submission->is_consolidated)->toBeTrue()
        ->and($submission->posOrders()->wherePivot('is_active', true)->pluck('pos_orders.id')->all())->toBe([$order->id])
        ->and($order->fresh()->e_invoice_submission_id)->toBe($submission->id);
    expect(posLhdnConsolidate($s))->toBe([]);
});

it('nets a same-month full refund to nothing and omits both orders', function () {
    $s = posLhdnSetup();
    $order = posLhdnSale($s, $s['steak']);
    $line = $order->lines()->sole();
    app(RefundPosOrder::class)($s['manager'], $s['property'], $order, [new PosRefundLineInputData($line->id, 1)], 'Returned', (string) Str::uuid(), $s['manager']);

    expect(posLhdnConsolidate($s))->toBe([])
        ->and($order->fresh()->e_invoice_submission_id)->toBeNull();
});

it('reports a same-month partial refund as a negative line', function () {
    $s = posLhdnSetup();
    $order = posLhdnSale($s, $s['steak'], 2);
    $line = $order->lines()->sole();
    $refund = app(RefundPosOrder::class)($s['manager'], $s['property'], $order, [new PosRefundLineInputData($line->id, 1)], 'Returned', (string) Str::uuid(), $s['manager'])->model;

    [$submission] = posLhdnConsolidate($s);
    $lines = data_get($submission->document_payload, 'Invoice.0.InvoiceLine');

    expect($submission->posOrders()->count())->toBe(2)
        ->and($lines)->toHaveCount(2)
        ->and($lines[1]['LineExtensionAmount'][0]['_'])->toEqual(-110.0)
        ->and($refund->fresh()->e_invoice_submission_id)->toBe($submission->id);
});

it('leaves out a sale over RM 10,000 and a sale already charged to a room', function () {
    $s = posLhdnSetup();
    $big = posLhdnSale($s, $s['steak'], 100);
    $big->forceFill(['grand_total' => '10000.01'])->save();
    $room = posLhdnSale($s, $s['pasta']);
    $room->forceFill(['folio_posted_amount' => $room->grand_total])->save();

    expect(posLhdnConsolidate($s))->toBe([]);
});

it('frees a rejected summary orders so they are filed again', function () {
    $s = posLhdnSetup();
    $order = posLhdnSale($s, $s['steak']);
    [$submission] = posLhdnConsolidate($s);

    $submission->forceFill(['status' => EInvoiceStatus::Invalid])->save();

    expect($order->fresh()->e_invoice_submission_id)->toBeNull()
        ->and($submission->posOrders()->wherePivot('is_active', true)->count())->toBe(0);

    [$again] = posLhdnConsolidate($s);

    expect($again->id)->not->toBe($submission->id)
        ->and($order->fresh()->e_invoice_submission_id)->toBe($again->id)
        ->and($again->posOrders()->wherePivot('is_active', true)->count())->toBe(1);
});

it('issues an individual e-invoice for a walk-in sale', function () {
    $s = posLhdnSetup();
    $order = posLhdnSale($s, $s['steak']);
    $buyer = ['name' => 'Acme Sdn Bhd', 'tin' => 'C9876543210', 'id_type' => 'BRN', 'id_value' => '202001012345', 'city' => 'Kuala Lumpur', 'postal_code' => '50000', 'state_code' => '14', 'country_code' => 'MYS', 'email' => 'ap@acme.my', 'phone' => '+60123456789'];

    $result = app(SubmitPosWalkInEInvoice::class)($order, $s['manager'], $buyer);
    $submission = $result->submission;

    expect($result->ok)->toBeTrue()
        ->and($submission->scenario)->toBe(EInvoiceScenario::PosOrder)
        ->and($order->fresh()->e_invoice_submission_id)->toBe($submission->id);

    expect(app(SubmitPosWalkInEInvoice::class)($order->fresh(), $s['manager'], $buyer)->ok)->toBeFalse();
    expect(posLhdnConsolidate($s))->toBe([]);

    $order->refresh();
    expect(EInvoiceSubmission::query()->where('scenario', EInvoiceScenario::PosOrder)->count())->toBe(1);
});
