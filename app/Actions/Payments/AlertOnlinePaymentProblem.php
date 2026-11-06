<?php

namespace App\Actions\Payments;

use App\Models\GatewayPayment;
use App\Models\User;
use App\Notifications\OnlinePaymentNeedsAttention;
use Illuminate\Support\Facades\Notification;

/**
 * Tell every active platform admin that a guest's online payment needs someone to act. `$problem` is safe to show.
 */
class AlertOnlinePaymentProblem
{
    public function __invoke(GatewayPayment $payment, string $problem): void
    {
        $payment->loadMissing('property');
        $admins = User::query()->where('is_admin', true)->whereNull('deactivated_at')->get();

        Notification::send($admins, new OnlinePaymentNeedsAttention(
            $payment->property->name,
            $payment->property->code,
            $payment->order_id,
            $payment->gateway_payment_id,
            $payment->amount,
            $problem,
        ));
    }
}
