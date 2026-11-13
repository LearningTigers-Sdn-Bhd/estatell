<?php

namespace App\Actions\Concierge;

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMethodType;
use App\Enums\UnitStatus;
use App\Models\GatewayPayment;
use App\Models\PaymentMethod;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeBill;
use App\Services\FrontDesk\SelfCheckInPayment;
use App\Services\Kiosk\KioskStays;
use App\Services\Payments\CurlecGateway;
use App\Services\Payments\CurlecRequestFailed;
use Brick\Money\Money;
use Illuminate\Support\Str;

class StartStayPayment
{
    public function __construct(private CurlecGateway $gateway, private SelfCheckInPayment $payment, private KioskStays $stays, private ConciergeBill $bill, private RecordStayPayment $record) {}

    /**
     * An open order the guest already paid, such as after a bank redirect that never returned, would make Curlec refuse
     * the checkout. Ask Curlec, post what it captured, and let the caller see that nothing is due. Curlec being down
     * leaves the order as it was.
     */
    private function catchUpPaidOrders(ReservationUnit $stay): void
    {
        $open = GatewayPayment::query()->where('reservation_unit_id', $stay->id)->where('status', '<>', GatewayPaymentStatus::Paid)->get();

        foreach ($open as $payment) {
            try {
                $paymentId = $this->gateway->capturedPaymentId($payment->order_id);
            } catch (CurlecRequestFailed $failed) {
                report($failed);

                continue;
            }

            if ($paymentId !== null) {
                ($this->record)($payment, $paymentId);
            }
        }
    }

    /**
     * Create the Curlec order for what the stay owes: before check-in the amount the kiosk waits for
     * (`SelfCheckInPayment::shortfall`), and once the guest is checked in the whole open bill (`ConciergeBill::due`). A guest who retries with the same amount due gets the same unpaid order, so two orders
     * for one amount are never open. The order is created before its row is saved, because the call must not hold a
     * database lock. Refused, writing nothing, when the stay is not open for check-in, nothing is due, or the property has
     * no Online payment method. Curlec being down is `payment_unavailable`.
     * See docs/30-concierge/01-overview.md#payment.
     */
    public function __invoke(ReservationUnit $stay): StayPaymentResult
    {
        $stay->loadMissing('property.settings');

        $inHouse = $stay->status === UnitStatus::CheckedIn;

        if (! $inHouse && ($stay->status !== UnitStatus::Confirmed || ! in_array($this->stays->window($stay), [KioskStays::OPEN, KioskStays::EARLY], true))) {
            return StayPaymentResult::refused(StayPaymentResult::REFUSAL_NOT_OPEN, __('Check-in is not open for this booking.'));
        }

        $this->catchUpPaidOrders($stay);

        $due = $inHouse ? $this->bill->due($stay) : $this->payment->shortfall($stay);

        if ((float) $due <= 0) {
            return StayPaymentResult::refused(StayPaymentResult::REFUSAL_NOTHING_DUE, __('Nothing is due for this booking.'));
        }

        $hasMethod = PaymentMethod::query()->where('property_id', $stay->property_id)->where('type', PaymentMethodType::Online)->exists();

        if (! $hasMethod) {
            return StayPaymentResult::refused(StayPaymentResult::REFUSAL_UNAVAILABLE, __('Online payment is not available for this property. Pay at the front desk.'));
        }

        $open = GatewayPayment::query()
            ->where('reservation_unit_id', $stay->id)
            ->where('status', '<>', GatewayPaymentStatus::Paid)
            ->where('amount', $due)
            ->latest('id')
            ->first();

        if ($open !== null) {
            return StayPaymentResult::started($open);
        }

        $currency = $stay->property->settings->currency->getCurrencyCode();

        try {
            $orderId = $this->gateway->createOrder(
                Money::of($due, $currency)->getMinorAmount()->toInt(),
                $currency,
                'stay-'.$stay->id.'-'.Str::lower(Str::random(8)),
                'stay-'.$stay->id,
            );
        } catch (CurlecRequestFailed $failed) {
            report($failed);

            return StayPaymentResult::refused(StayPaymentResult::REFUSAL_UNAVAILABLE, __('Please try again later, or visit the front desk to pay.'));
        }

        return StayPaymentResult::started(GatewayPayment::query()->create([
            'property_id' => $stay->property_id,
            'reservation_unit_id' => $stay->id,
            'gateway_account' => $this->gateway->account(),
            'order_id' => $orderId,
            'amount' => $due,
            'status' => GatewayPaymentStatus::Created,
        ]));
    }
}
