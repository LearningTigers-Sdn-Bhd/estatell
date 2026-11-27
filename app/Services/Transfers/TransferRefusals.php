<?php

namespace App\Services\Transfers;

use App\Actions\Transfers\UnitTransferResult;

/**
 * The words and the form field of each land transfer refusal.
 */
class TransferRefusals
{
    /**
     * @return array<string, string> One field and its message.
     */
    public function errors(string $refusal): array
    {
        return match ($refusal) {
            UnitTransferResult::REFUSAL_UNIT_NOT_OPEN => ['form' => __('This room can no longer change this transfer.')],
            UnitTransferResult::REFUSAL_ROUTE_NOT_FOUND => ['transfer_route_id' => __('Pick an available route of this direction.')],
            UnitTransferResult::REFUSAL_CHARGE_NOT_FOUND => ['mode' => __('This route\'s price is not available. Turn its charge item on or change the route\'s price.')],
            UnitTransferResult::REFUSAL_VEHICLE_NOT_FOUND => ['transfer_vehicle_id' => __('Pick an available vehicle.')],
            UnitTransferResult::REFUSAL_DRIVER_NOT_FOUND => ['transfer_driver_id' => __('Pick an available driver.')],
            'transfer_not_found' => ['form' => __('A transfer is no longer on the run sheet. Reload the page.')],
            default => ['form' => __('The transfer could not be saved.')],
        };
    }
}
