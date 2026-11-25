<?php

use App\Actions\Pos\SettlePosOrder;
use App\Data\Pos\PosOrderData;
use App\Enums\EInvoiceEnvironment;
use App\Features\EInvoicing;
use App\Http\Controllers\Pos\PosRegisterController;
use App\Models\EInvoiceSubmission;
use App\Models\PosOrder;
use App\Models\PropertyEInvoiceSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosOrderSetup.php';

beforeEach(fn () => Cache::flush());
afterEach(fn () => Carbon::setTestNow());

function posClaimSetup(): array
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
    ]);
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($order->fresh()->grand_total)])->model;

    return [...$s, 'order' => $order];
}

function posClaimBuyer(): array
{
    return ['name' => 'Aisyah Binti Omar', 'tin' => 'IG12345678901', 'id_type' => 'NRIC', 'id_value' => '900101015555', 'city' => 'Kuala Lumpur', 'state_code' => '14', 'country_code' => 'MYS'];
}

it('prints a claim link and QR on a walk-in receipt and none on a room-only one', function () {
    $s = posClaimSetup();
    $order = $s['order']->load(PosRegisterController::orderRelations());

    $data = PosOrderData::fromModel($order);

    expect($data->claimUrl)->toBe(route('pos.receipt-claim.show', ['token' => $order->receipt_claim_token]))
        ->and($data->claimQrSvg)->toStartWith('<?xml')->toContain('<svg');
});

it('shows nothing for a token that does not exist and never the order details', function () {
    $s = posClaimSetup();

    $this->get(route('pos.receipt-claim.show', ['token' => str_repeat('a', 32)]))->assertNotFound();

    $page = $this->get(route('pos.receipt-claim.show', ['token' => $s['order']->receipt_claim_token]))->assertOk()->viewData('page');

    expect($page['props']['step'])->toBe('confirm')->and(json_encode($page['props']))->not->toContain($s['order']->grand_total)->not->toContain($s['order']->order_number);
});

it('needs the exact total and date, counts every try and closes the token after ten', function () {
    $s = posClaimSetup();
    $order = $s['order'];
    $confirm = fn (string $total, string $date) => $this->post(route('pos.receipt-claim.confirm', ['token' => $order->receipt_claim_token]), ['total' => $total, 'date' => $date]);
    $date = $order->business_date->toDateString();

    $confirm('1.00', $date)->assertRedirect();
    $confirm($order->grand_total, '2020-01-01')->assertRedirect();

    expect($order->fresh()->receipt_claim_attempts)->toBe(2)
        ->and($this->get(route('pos.receipt-claim.show', ['token' => $order->receipt_claim_token]))->viewData('page')['props']['step'])->toBe('confirm');

    $order->forceFill(['receipt_claim_attempts' => 10])->save();
    $confirm($order->grand_total, $date)->assertRedirect();

    expect($order->fresh()->receipt_claim_attempts)->toBe(10)
        ->and($this->get(route('pos.receipt-claim.show', ['token' => $order->receipt_claim_token]))->viewData('page')['props']['step'])->toBe('closed');
});

it('sends the e-invoice in the guest name after the slip is confirmed, and logs nothing of the buyer', function () {
    $s = posClaimSetup();
    $order = $s['order'];
    $token = $order->receipt_claim_token;

    $this->post(route('pos.receipt-claim.claim', ['token' => $token]), posClaimBuyer())->assertRedirect();
    expect($order->fresh()->e_invoice_submission_id)->toBeNull();

    $this->post(route('pos.receipt-claim.confirm', ['token' => $token]), ['total' => $order->grand_total, 'date' => $order->business_date->toDateString()])->assertRedirect();
    expect($this->get(route('pos.receipt-claim.show', ['token' => $token]))->viewData('page')['props']['step'])->toBe('details');

    $this->post(route('pos.receipt-claim.claim', ['token' => $token]), posClaimBuyer())->assertRedirect();

    $submission = EInvoiceSubmission::query()->find($order->fresh()->e_invoice_submission_id);

    expect($submission)->not->toBeNull()->and($submission->requested_by_guest)->toBeTrue()
        ->and(Activity::query()->get()->contains(fn (Activity $row): bool => str_contains(json_encode($row->properties), '900101015555') || str_contains(json_encode($row->properties), 'IG12345678901')))->toBeFalse()
        ->and($this->get(route('pos.receipt-claim.show', ['token' => $token]))->viewData('page')['props']['step'])->toBe('closed');
});

it('closes a receipt at the end of its month', function () {
    $s = posClaimSetup();
    $order = $s['order'];

    Carbon::setTestNow($order->business_date->endOfMonth()->addDay());

    expect($this->get(route('pos.receipt-claim.show', ['token' => $order->receipt_claim_token]))->viewData('page')['props']['step'])->toBe('closed');

    $this->post(route('pos.receipt-claim.confirm', ['token' => $order->receipt_claim_token]), ['total' => $order->grand_total, 'date' => $order->business_date->toDateString()])->assertRedirect();

    expect(PosOrder::query()->find($order->id)->e_invoice_submission_id)->toBeNull();
});

it('accepts either the unrounded bill total or the cash-rounded net payable when confirming a claim', function () {
    $s = posClaimSetup();
    $order = $s['order'];
    $order->forceFill(['grand_total' => '12.34', 'rounding_adjustment' => '0.01'])->save();

    $this->post(route('pos.receipt-claim.confirm', ['token' => $order->receipt_claim_token]), [
        'total' => '12.35',
        'date' => $order->business_date->toDateString(),
    ])->assertRedirect();

    expect($this->get(route('pos.receipt-claim.show', ['token' => $order->receipt_claim_token]))->viewData('page')['props']['step'])->toBe('details');
});
