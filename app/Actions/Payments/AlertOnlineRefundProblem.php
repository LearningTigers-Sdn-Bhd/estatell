<?php

namespace App\Actions\Payments;

use App\Enums\NotificationType;
use App\Models\GatewayRefund;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\OnlineRefundNeedsAttention;
use Illuminate\Support\Facades\Notification;

class AlertOnlineRefundProblem
{
    public function __construct(private AlertOnlinePaymentProblem $alertAdmins) {}

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

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($refund->property_id);

        try {
            $staff = User::query()
                ->where('property_id', $refund->property_id)
                ->where('is_admin', false)
                ->whereNull('deactivated_at')
                ->permission((string) NotificationType::OnlineRefundNeedsAttention->recipientPermission())
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }

        Notification::send($staff, $message);
    }
}
