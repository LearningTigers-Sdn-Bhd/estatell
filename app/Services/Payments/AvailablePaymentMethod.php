<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethodType;
use App\Enums\PermissionEnum;
use App\Enums\SurchargeType;
use App\Enums\TransactionKind;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Property\MigrationWindow;

/**
 * Finds the payment method that staff can take money with on a reservation: an active method of its property. City
 * ledger and OTA collected are not offered, the method's code must be an active payment code, and a surcharge needs
 * an active charge item on an active charge code. The system Old system method is offered by the migration window and
 * `folio.manual`, not by its `active` flag, and never has a surcharge. `RecordPayment` and the manual folio preview
 * both use it.
 */
class AvailablePaymentMethod
{
    public function __construct(private MigrationWindow $migrationWindow) {}

    public function find(Reservation $reservation, int $methodId, User $actor, bool $otaCollected = false): ?PaymentMethod
    {
        $method = PaymentMethod::query()
            ->where('property_id', $reservation->property_id)
            ->when(
                $otaCollected,
                fn ($query) => $query->where('type', PaymentMethodType::OtaCollected),
                fn ($query) => $query->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill]),
            )
            ->with(['transactionCode', 'surchargeChargeItem.transactionCode'])
            ->find($methodId);

        if ($method === null) {
            return null;
        }

        if ($method->type === PaymentMethodType::OldSystem) {
            $codeUsable = $method->transactionCode->active && $method->transactionCode->kind === TransactionKind::Payment;

            return $codeUsable && $this->oldSystemOffered($reservation->property, $actor) ? $method : null;
        }

        if (! $method->active || ! $method->transactionCode->active || $method->transactionCode->kind !== TransactionKind::Payment) {
            return null;
        }

        if ($method->surcharge_type === SurchargeType::None) {
            return $method;
        }

        $item = $method->surchargeChargeItem;

        return $item !== null && $item->active && $item->transactionCode->active && $item->transactionCode->kind === TransactionKind::Charge ? $method : null;
    }

    /**
     * Whether the user may take an Old system payment now: `folio.manual` and an open migration window.
     */
    public function oldSystemOffered(Property $property, User $actor): bool
    {
        return $actor->can(PermissionEnum::FolioManual->value) && $this->migrationWindow->isOpen($property);
    }
}
