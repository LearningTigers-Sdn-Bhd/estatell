<?php

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\GatewayPayment;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TransactionCode;
use App\Services\Payments\CurlecGateway;

require_once __DIR__.'/../Support/ConciergeSetup.php';
require_once __DIR__.'/../Support/CurlecSetup.php';

/**
 * A stay that owes a RM 100 deposit, with the ID done, an Online payment method and the fake gateway.
 *
 * @return array<string, mixed> The `conciergeSetup()` keys plus `online` and `gateway`.
 */
function conciergePaymentSetup(): array
{
    config(['services.curlec.key_id' => 'rzp_test_key', 'services.curlec.key_secret' => CURLEC_KEY_SECRET, 'services.curlec.webhook_secret' => CURLEC_WEBHOOK_SECRET, 'services.curlec.account' => 'primary']);

    $setup = conciergeSetup();
    $property = $setup['property'];
    $property->settings->forceFill(['security_deposit_amount' => '100.00'])->save();
    conciergeIdentityDone($setup['guest']);
    Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $property->id, 'reservation_id' => $setup['unit']->reservation_id]);

    $code = TransactionCode::factory()->for($property)->system(SystemTransactionCode::OnlinePayment)->create();
    $online = PaymentMethod::factory()->create(['property_id' => $property->id, 'transaction_code_id' => $code->id, 'name' => 'Online', 'type' => PaymentMethodType::Online, 'requires_reference' => false]);
    $gateway = new FakeCurlecGateway;
    app()->instance(CurlecGateway::class, $gateway);

    return [...$setup, 'online' => $online, 'gateway' => $gateway];
}

it('creates the order for what is due and gives the page what its checkout needs', function () {
    ['token' => $token, 'unit' => $unit, 'gateway' => $gateway] = conciergePaymentSetup();

    $this->postJson("/c/{$token}/payment")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['key' => 'rzp_test_key', 'order_id' => 'order_1', 'amount' => 10000, 'currency' => 'MYR', 'name' => 'Palm Resort']);

    $order = GatewayPayment::query()->sole();
    expect($order->reservation_unit_id)->toBe($unit->id)
        ->and($order->booking_hold_id)->toBeNull()
        ->and($order->amount)->toBe('100.00')
        ->and($order->status)->toBe(GatewayPaymentStatus::Created)
        ->and($gateway->orders)->toHaveCount(1)
        ->and($gateway->orders[0]['amount'])->toBe(10000)
        ->and($gateway->orders[0]['receipt'])->toStartWith('stay-'.$unit->id.'-');
});

it('gives the same open order again, so one amount is never two orders', function () {
    ['token' => $token, 'gateway' => $gateway] = conciergePaymentSetup();

    $first = $this->postJson("/c/{$token}/payment")->json('order_id');
    $second = $this->postJson("/c/{$token}/payment")->json('order_id');

    expect($second)->toBe($first)->and($gateway->orders)->toHaveCount(1);
});

it('refuses to start a payment when nothing is due, when Curlec is down and when the property has no online method', function () {
    ['token' => $token, 'unit' => $unit, 'property' => $property, 'gateway' => $gateway, 'online' => $online] = conciergePaymentSetup();

    $gateway->failing = true;
    $this->postJson("/c/{$token}/payment")->assertStatus(409)->assertJsonPath('message', 'Please try again later, or visit the front desk to pay.');
    expect(GatewayPayment::query()->count())->toBe(0);
    $gateway->failing = false;

    $online->delete();
    $this->postJson("/c/{$token}/payment")->assertStatus(409);

    $property->settings->forceFill(['security_deposit_amount' => '0.00'])->save();
    $this->postJson("/c/{$token}/payment")->assertStatus(409);
    expect($unit->refresh()->status->value)->toBe('confirmed')->and(GatewayPayment::query()->count())->toBe(0);
});

it('posts the payment on the unit folio once the guest confirms it with a good signature', function () {
    ['token' => $token, 'unit' => $unit] = conciergePaymentSetup();
    $order = $this->postJson("/c/{$token}/payment")->json('order_id');
    $body = ['razorpay_order_id' => $order, 'razorpay_payment_id' => 'pay_123', 'razorpay_signature' => conciergeSignature($order, 'pay_123')];

    $this->post("/c/{$token}/payment/confirm", $body)->assertRedirect("/c/{$token}");
    $this->post("/c/{$token}/payment/confirm", $body)->assertRedirect("/c/{$token}");

    $payment = Payment::query()->where('reference', 'pay_123')->sole();
    expect($payment->amount)->toBe('100.00')
        ->and($payment->purpose)->toBe(PaymentPurpose::Advance)
        ->and(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and(GatewayPayment::query()->sole()->gateway_payment_id)->toBe('pay_123');
    conciergeStep($token, 'check_in');
});

it('ignores a bad signature and an order of another stay', function () {
    ['token' => $token, 'property' => $property, 'unit' => $unit] = conciergePaymentSetup();
    $order = $this->postJson("/c/{$token}/payment")->json('order_id');

    $this->post("/c/{$token}/payment/confirm", ['razorpay_order_id' => $order, 'razorpay_payment_id' => 'pay_123', 'razorpay_signature' => 'forged'])->assertRedirect("/c/{$token}");

    $other = kioskStayFor($property, 'R-400', guest: ['full_name' => 'Ben Lee']);
    $foreign = GatewayPayment::factory()->create(['booking_hold_id' => null, 'reservation_unit_id' => $other['unit']->id, 'property_id' => $property->id, 'order_id' => 'order_foreign', 'amount' => '100.00']);
    $this->post("/c/{$token}/payment/confirm", ['razorpay_order_id' => 'order_foreign', 'razorpay_payment_id' => 'pay_9', 'razorpay_signature' => conciergeSignature('order_foreign', 'pay_9')])->assertRedirect("/c/{$token}");

    expect(Payment::query()->count())->toBe(0)
        ->and(GatewayPayment::query()->where('order_id', $order)->sole()->status)->toBe(GatewayPaymentStatus::Created)
        ->and($foreign->refresh()->status)->toBe(GatewayPaymentStatus::Created);
});

it('posts the payment from the webhook when the guest never came back to the page, and only once', function () {
    ['token' => $token] = conciergePaymentSetup();
    $order = $this->postJson("/c/{$token}/payment")->json('order_id');

    postCurlecWebhook(curlecOrderPaid($order, 'pay_77'))->assertOk();
    postCurlecWebhook(curlecOrderPaid($order, 'pay_77'), 'evt_2')->assertOk();

    expect(Payment::query()->where('reference', 'pay_77')->count())->toBe(1);
    conciergeStep($token, 'check_in');
});

it('lets the page and the webhook both report the same payment without posting it twice', function () {
    ['token' => $token] = conciergePaymentSetup();
    $order = $this->postJson("/c/{$token}/payment")->json('order_id');

    $this->post("/c/{$token}/payment/confirm", ['razorpay_order_id' => $order, 'razorpay_payment_id' => 'pay_55', 'razorpay_signature' => conciergeSignature($order, 'pay_55')]);
    postCurlecWebhook(curlecOrderPaid($order, 'pay_55'))->assertOk();

    expect(Payment::query()->where('reference', 'pay_55')->count())->toBe(1);
});

it('still posts booking engine payments the way it did', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    $order = curlecOrder($hold);

    postCurlecWebhook(curlecOrderPaid($order->order_id, 'pay_hold'))->assertOk();

    expect($order->refresh()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and($order->reservation_unit_id)->toBeNull();
});
