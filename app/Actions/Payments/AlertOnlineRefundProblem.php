<?php

namespace App\Actions\Payments;

use App\Enums\NotificationType;
use App\Models\GatewayRefund;
use App\Models\Reservation;
use App\Notifications\OnlineRefundNeedsAttention;
use App\Services\Staff\PropertyStaff;
use Illuminate\Support\Facades\Notification;

class AlertOnlineRefundProblem
{
    public function __construct(private AlertOnlinePaymentProblem $alertAdmins, private PropertyStaff $propertyStaff) {}

    /**
     * Tell platform admins that an online refund needs someone to act and, unless `$toStaff` is false, the property's staff
     * with `payment.refund`. `$problem` is safe to show.
     */
    public function __invoke(GatewayRefund $refund, string $problem, bool $toStaff = true): void
    {
        $refund->loadMissing(['gatewayPayment.hold', 'property']);
        ($this->alertAdmins)($refund->gatewayPayment, $problem);

        if (! $toStaff) {
            return;
        }

        $reservationId = $refund->gatewayPayment->hold->reservation_id;
        $reference = $reservationId === null ? null : Reservation::query()->whereKey($reservationId)->value('reference');
        $message = new OnlineRefundNeedsAttention($refund->property->code, $refund->property->name, $reservationId, $reference, $problem);

        $staff = $this->propertyStaff->withPermission($refund->property_id, (string) NotificationType::OnlineRefundNeedsAttention->recipientPermission());

        Notification::send($staff, $message);
    }
}
