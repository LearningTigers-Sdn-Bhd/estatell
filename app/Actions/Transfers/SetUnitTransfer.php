<?php

namespace App\Actions\Transfers;

use App\Data\Transfers\UnitTransferInputData;
use App\Models\ReservationUnit;
use App\Models\TransferRoute;
use App\Models\UnitTransfer;
use App\Models\User;
use App\Services\Transfers\TransferAccess;
use Illuminate\Support\Facades\DB;

class SetUnitTransfer
{
    public function __construct(
        private TransferAccess $transferAccess,
        private SyncTransferCharge $syncTransferCharge,
    ) {}

    /**
     * Set how one unit travels in or out by road. Locks the unit, checks it is still open and that the route is an
     * available route of the property in that direction, then writes the one row for the unit and direction. The charge
     * is synced only when the type or the route changes, so a new pick-up time or flight number leaves it alone. A charge
     * refusal rolls everything back. A set that changes nothing writes and logs nothing. Logs `reservation.transfer_set`.
     * See docs/35-transfers/01-land-transfers.md#setunittransfer.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, UnitTransferInputData $data): UnitTransferResult
    {
        try {
            return DB::transaction(fn (): UnitTransferResult => $this->save($actor, $unit, $data));
        } catch (TransferRefused $refused) {
            return UnitTransferResult::refused($refused->refusal);
        }
    }

    private function save(?User $actor, ReservationUnit $unit, UnitTransferInputData $data): UnitTransferResult
    {
        $locked = ReservationUnit::query()->with('reservation')->lockForUpdate()->findOrFail($unit->id);
        $refusal = $this->transferAccess->refusalFor($locked, $data->direction);

        if ($refusal !== null) {
            return UnitTransferResult::refused($refusal);
        }

        $route = TransferRoute::query()
            ->whereKey($data->routeId)
            ->where('property_id', $locked->property_id)
            ->where('direction', $data->direction->value)
            ->where('active', true)
            ->first();

        if ($route === null) {
            return UnitTransferResult::refused(UnitTransferResult::REFUSAL_ROUTE_NOT_FOUND);
        }

        $time = substr($data->pickupTime, 0, 5).':00';
        $flightNumber = filled($data->flightNumber) ? mb_strtoupper(trim((string) $data->flightNumber)) : null;
        $note = filled($data->note) ? trim((string) $data->note) : null;

        $existing = UnitTransfer::query()
            ->where('reservation_unit_id', $locked->id)
            ->where('direction', $data->direction->value)
            ->first();

        $chargeChanges = $existing === null || $existing->mode !== $data->mode || $existing->transfer_route_id !== $route->id;

        if (! $chargeChanges && substr($existing->pickup_time, 0, 5) === substr($time, 0, 5) && $existing->flight_number === $flightNumber && $existing->note === $note) {
            return UnitTransferResult::done();
        }

        $transfer = UnitTransfer::query()->updateOrCreate(
            ['reservation_unit_id' => $locked->id, 'direction' => $data->direction->value],
            [
                'property_id' => $locked->property_id,
                'mode' => $data->mode->value,
                'transfer_route_id' => $route->id,
                'pickup_time' => $time,
                'flight_number' => $flightNumber,
                'note' => $note,
            ],
        );

        $charge = $chargeChanges
            ? ($this->syncTransferCharge)($actor, $locked, $transfer, $route, $data->mode)
            : UnitTransferResult::done();

        if ($charge->isRefused()) {
            throw new TransferRefused((string) $charge->refusal);
        }

        activity()
            ->performedOn($locked->reservation)
            ->causedBy($actor)
            ->event('reservation.transfer_set')
            ->withProperties([
                'property_id' => $locked->property_id,
                'reservation_unit_id' => $locked->id,
                'direction' => $data->direction->value,
                'mode' => $data->mode->value,
                'transfer_route_id' => $route->id,
                'route' => $route->name,
                'pickup_time' => substr($time, 0, 5),
                'flight_number' => $flightNumber,
            ])
            ->log(__(':direction: :mode, :route at :time.', [
                'direction' => $data->direction->label(),
                'mode' => $data->mode->label(),
                'route' => $route->name,
                'time' => substr($time, 0, 5),
            ]));

        return UnitTransferResult::done($charge->postedChargeKept);
    }
}
