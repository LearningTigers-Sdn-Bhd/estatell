<?php

namespace App\Actions\Transfers;

use App\Models\Property;
use App\Models\UnitTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignTransferRun
{
    public function __construct(private AssignTransfer $assign) {}

    /**
     * Put several transfers of the property on one van run: the same vehicle and driver (null for Not set) and, when
     * `$pickupTime` (`H:i`) is given, the same pick-up time. Each transfer goes through `AssignTransfer`, so the same
     * checks and log apply; a new pick-up time keeps the charge and logs `reservation.transfer_time_changed`. A refusal
     * on any transfer rolls every change back. Ids of another property are refused as `transfer_not_found`.
     * See docs/35-transfers/01-land-transfers.md#vans-board.
     *
     * @param  list<int>  $transferIds
     */
    public function __invoke(User $actor, Property $property, array $transferIds, ?int $vehicleId, ?int $driverId, ?string $pickupTime): UnitTransferResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $transferIds, $vehicleId, $driverId, $pickupTime): UnitTransferResult {
                $transfers = UnitTransfer::query()
                    ->where('property_id', $property->id)
                    ->whereKey($transferIds)
                    ->with('reservationUnit.reservation')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($transfers->count() !== count(array_unique($transferIds))) {
                    throw new TransferRunRefused('transfer_not_found');
                }

                foreach ($transfers as $transfer) {
                    $result = ($this->assign)($actor, $transfer, $vehicleId, $driverId);

                    if ($result->isRefused()) {
                        throw new TransferRunRefused((string) $result->refusal);
                    }

                    if ($pickupTime !== null && substr($transfer->pickup_time, 0, 5) !== $pickupTime) {
                        $from = substr($transfer->pickup_time, 0, 5);
                        $transfer->update(['pickup_time' => $pickupTime]);

                        activity()
                            ->performedOn($transfer->reservationUnit->reservation)
                            ->causedBy($actor)
                            ->event('reservation.transfer_time_changed')
                            ->withProperties([
                                'property_id' => $property->id,
                                'reservation_unit_id' => $transfer->reservation_unit_id,
                                'direction' => $transfer->direction->value,
                                'from' => $from,
                                'to' => $pickupTime,
                            ])
                            ->log(__(':direction pick-up time :from → :to.', ['direction' => $transfer->direction->label(), 'from' => $from, 'to' => $pickupTime]));
                    }
                }

                return UnitTransferResult::done();
            });
        } catch (TransferRunRefused $refused) {
            return UnitTransferResult::refused($refused->getMessage());
        }
    }
}
