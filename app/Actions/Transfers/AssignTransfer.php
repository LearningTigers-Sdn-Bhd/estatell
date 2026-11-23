<?php

namespace App\Actions\Transfers;

use App\Models\TransferDriver;
use App\Models\TransferVehicle;
use App\Models\UnitTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignTransfer
{
    /**
     * Pick the vehicle and the driver of one transfer on the run sheet, or none (null). Each must be an available one of
     * the transfer's property. The charge does not change. A pick that changes nothing writes and logs nothing. Logs
     * `reservation.transfer_assigned`. See docs/34-transfers/01-land-transfers.md#run-sheet.
     */
    public function __invoke(User $actor, UnitTransfer $transfer, ?int $vehicleId, ?int $driverId): UnitTransferResult
    {
        return DB::transaction(function () use ($actor, $transfer, $vehicleId, $driverId): UnitTransferResult {
            $locked = UnitTransfer::query()->with('reservationUnit.reservation')->lockForUpdate()->findOrFail($transfer->id);

            if ($vehicleId !== null && $vehicleId !== $locked->transfer_vehicle_id
                && ! TransferVehicle::query()->where('property_id', $locked->property_id)->where('active', true)->whereKey($vehicleId)->exists()) {
                return UnitTransferResult::refused(UnitTransferResult::REFUSAL_VEHICLE_NOT_FOUND);
            }

            if ($driverId !== null && $driverId !== $locked->transfer_driver_id
                && ! TransferDriver::query()->where('property_id', $locked->property_id)->where('active', true)->whereKey($driverId)->exists()) {
                return UnitTransferResult::refused(UnitTransferResult::REFUSAL_DRIVER_NOT_FOUND);
            }

            if ($locked->transfer_vehicle_id === $vehicleId && $locked->transfer_driver_id === $driverId) {
                return UnitTransferResult::done();
            }

            $locked->update(['transfer_vehicle_id' => $vehicleId, 'transfer_driver_id' => $driverId]);

            activity()
                ->performedOn($locked->reservationUnit->reservation)
                ->causedBy($actor)
                ->event('reservation.transfer_assigned')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->reservation_unit_id,
                    'direction' => $locked->direction->value,
                    'transfer_vehicle_id' => $vehicleId,
                    'transfer_driver_id' => $driverId,
                ])
                ->log(__(':direction vehicle and driver set.', ['direction' => $locked->direction->label()]));

            return UnitTransferResult::done();
        });
    }
}
