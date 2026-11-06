<?php

namespace App\Actions\Payments;

use App\Enums\GatewayRefundStatus;
use App\Models\GatewayRefund;
use App\Services\Payments\CurlecGateway;
use App\Services\Payments\CurlecRefundRefused;
use App\Services\Payments\CurlecRequestFailed;
use Brick\Money\Money;

class SendOnlineRefund
{
    public function __construct(
        private CurlecGateway $gateway,
        private FailOnlineRefund $failOnlineRefund,
        private AlertOnlineRefundProblem $alert,
    ) {}

    /**
     * Send a Pending refund to Curlec, once it has no Curlec ID. A refund Curlec refuses for good becomes Failed. A problem
     * worth another try (Curlec unreachable, balance too low) keeps it Pending for `ResendWaitingOnlineRefunds`, and admins
     * are alerted the first time. The receipt holds the attempt, because Curlec treats a repeated receipt as a repeat.
     * See docs/29-booking-engine/05-cancel-and-refund.md#when-a-refund-cannot-run.
     */
    public function __invoke(GatewayRefund $refund): void
    {
        $current = GatewayRefund::query()->with(['gatewayPayment', 'property.settings'])->findOrFail($refund->id);

        if ($current->status !== GatewayRefundStatus::Pending || $current->gateway_refund_id !== null) {
            return;
        }

        $currency = $current->property->settings->currency->getCurrencyCode();

        try {
            $curlecRefundId = $this->gateway->refund(
                (string) $current->gatewayPayment->gateway_payment_id,
                Money::of($current->amount, $currency)->getMinorAmount()->toInt(),
                "refund-{$current->id}-{$current->attempts}",
                $current->id,
            );
        } catch (CurlecRefundRefused $refused) {
            ($this->failOnlineRefund)($current, $refused->getMessage());

            return;
        } catch (CurlecRequestFailed $failed) {
            $isFirstProblem = $current->failure_reason === null;
            $current->touch();
            $current->update(['failure_reason' => $failed->getMessage()]);

            if ($isFirstProblem) {
                ($this->alert)($current, __('A refund of :amount could not reach Curlec (:problem). It will be tried again.', ['amount' => $current->amount, 'problem' => $failed->getMessage()]), toStaff: false);
            }

            return;
        }

        GatewayRefund::query()
            ->whereKey($current->id)
            ->whereNull('gateway_refund_id')
            ->where('status', GatewayRefundStatus::Pending)
            ->update(['failure_reason' => null, 'updated_at' => now(), ...($curlecRefundId === null ? [] : ['gateway_refund_id' => $curlecRefundId])]);
    }
}
