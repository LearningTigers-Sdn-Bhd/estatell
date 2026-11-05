<?php

require_once __DIR__.'/BookingApiSetup.php';

use App\Enums\BookingSourceType;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\BookingHold;
use App\Models\BookingHoldLine;
use App\Models\BusinessDate;
use App\Models\GatewayPayment;
use App\Models\PaymentMethod;
use App\Models\TransactionCode;
use App\Services\Payments\CurlecGateway;
use App\Services\Payments\CurlecRequestFailed;
use Database\Factories\BookingSourceFactory;
use Illuminate\Testing\TestResponse;

const CURLEC_WEBHOOK_SECRET = 'test-webhook-secret';

const CURLEC_KEY_SECRET = 'test-key-secret';

/**
 * Stands in for Curlec in tests, so no test calls it. Signatures stay real. Set `failing` to act as if Curlec were down.
 */
class FakeCurlecGateway extends CurlecGateway
{
    /** @var list<array{amount: int, currency: string, receipt: string, token: string}> */
    public array $orders = [];

    public bool $failing = false;

    public function createOrder(int $amountInSubunits, string $currency, string $receipt, string $holdToken): string
    {
        if ($this->failing) {
            throw new CurlecRequestFailed('Curlec could not be reached.');
        }

        $this->orders[] = ['amount' => $amountInSubunits, 'currency' => $currency, 'receipt' => $receipt, 'token' => $holdToken];

        return 'order_'.count($this->orders);
    }
}

/**
 * A property the microsite reaches, on an open business date, with the Booking Engine source, the Online method, the
 * microsite client and the fake gateway.
 *
 * @return array<string, mixed> The `bookingApiProperty()` keys plus `client`, `online` and `gateway`.
 */
function curlecSetup(): array
{
    config(['services.curlec.key_id' => 'rzp_test_key', 'services.curlec.key_secret' => CURLEC_KEY_SECRET, 'services.curlec.webhook_secret' => CURLEC_WEBHOOK_SECRET, 'services.curlec.account' => 'primary']);

    $setup = bookingApiProperty();
    $property = $setup['property'];
    BusinessDate::factory()->create(['property_id' => $property->id]);
    BookingSourceFactory::systemFor($property->id, BookingSourceType::BookingEngine);
    $code = TransactionCode::factory()->for($property)->system(SystemTransactionCode::OnlinePayment)->create();
    $online = PaymentMethod::factory()->create(['property_id' => $property->id, 'transaction_code_id' => $code->id, 'name' => 'Online', 'type' => PaymentMethodType::Online, 'requires_reference' => false]);
    $gateway = new FakeCurlecGateway;
    app()->instance(CurlecGateway::class, $gateway);

    return [...$setup, 'client' => bookingMicrositeClient(), 'online' => $online, 'gateway' => $gateway];
}

/**
 * A hold of RM 200 for each room.
 *
 * @param  array<string, mixed>  $setup
 */
function curlecHold(array $setup, string $state = 'held', int $rooms = 1): BookingHold
{
    $factory = BookingHold::factory()->for($setup['property'])->state(['booking_client_id' => $setup['client']->id, 'total_amount' => (string) (200 * $rooms).'.00', 'special_requests' => 'Late arrival']);
    $hold = ($state === 'held' ? $factory : $factory->{$state}())->create();

    foreach (range(1, $rooms) as $position) {
        BookingHoldLine::factory()->create(['booking_hold_id' => $hold->id, 'room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'amount' => '200.00', 'room_total' => '200.00', 'position' => $position]);
    }

    return $hold;
}

function curlecOrder(BookingHold $hold, string $orderId = 'order_abc'): GatewayPayment
{
    return GatewayPayment::factory()->create(['booking_hold_id' => $hold->id, 'property_id' => $hold->property_id, 'order_id' => $orderId, 'amount' => $hold->total_amount]);
}

/**
 * @param  array<string, mixed>  $body
 */
function postCurlecWebhook(array $body, ?string $eventId = 'evt_1', ?string $signature = null): TestResponse
{
    return test()->postJson('/webhooks/curlec', $body, array_filter([
        'X-Razorpay-Event-Id' => $eventId,
        'X-Razorpay-Signature' => $signature ?? hash_hmac('sha256', (string) json_encode($body), CURLEC_WEBHOOK_SECRET),
    ]));
}

/**
 * @return array<string, mixed>
 */
function curlecOrderPaid(string $orderId = 'order_abc', string $paymentId = 'pay_123'): array
{
    return [
        'event' => 'order.paid',
        'payload' => [
            'payment' => ['entity' => ['id' => $paymentId, 'order_id' => $orderId, 'status' => 'captured']],
            'order' => ['entity' => ['id' => $orderId, 'status' => 'paid']],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function curlecPaymentFailed(string $orderId = 'order_abc'): array
{
    return ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_failed', 'order_id' => $orderId, 'status' => 'failed']]]];
}
