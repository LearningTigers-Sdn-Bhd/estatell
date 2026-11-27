<?php

namespace App\Services\Transfers;

use App\Data\Transfers\TransferRouteData;
use App\Data\Transfers\UnitTransferRowData;
use App\Enums\PermissionEnum;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\TransferRoute;
use App\Models\UnitTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The reservation page Transfers tab: a row per unit and direction, and the available routes for the Dialog. A cancelled
 * or no-show unit shows only the transfers it kept.
 * See docs/35-transfers/01-land-transfers.md#reservation-transfers-tab.
 */
class ReservationTransfers
{
    public function __construct(private TransferAccess $access) {}

    /**
     * @param  Collection<int, ReservationUnit>  $units
     * @param  array<int, string>  $labels  Unit label by unit id
     * @return array{transferRows: list<UnitTransferRowData>, transferRoutes: list<TransferRouteData>, transferModes: list<array{value: string, label: string}>}
     */
    public function tabProps(User $user, Property $property, Collection $units, array $labels): array
    {
        $canManage = $user->can(PermissionEnum::ReservationsManage->value);
        $unitIds = $units->modelKeys();

        $transfers = UnitTransfer::query()
            ->where('property_id', $property->id)
            ->whereIn('reservation_unit_id', $unitIds)
            ->with(['transferRoute', 'transferVehicle', 'transferDriver'])
            ->get()
            ->keyBy(fn (UnitTransfer $transfer): string => $transfer->reservation_unit_id.'-'.$transfer->direction->value);

        $rows = [];

        foreach ($units as $unit) {
            if (! isset($labels[$unit->id])) {
                continue;
            }

            foreach (TransferDirection::cases() as $direction) {
                $transfer = $transfers->get($unit->id.'-'.$direction->value);

                if ($transfer === null && in_array($unit->status, [UnitStatus::Cancelled, UnitStatus::NoShow], true)) {
                    continue;
                }

                $rows[] = UnitTransferRowData::fromUnit(
                    $unit,
                    $labels[$unit->id],
                    $direction,
                    $transfer,
                    $canManage && $this->access->refusalFor($unit, $direction) === null,
                );
            }
        }

        return [
            'transferRows' => $rows,
            'transferRoutes' => $canManage ? $this->routes($property) : [],
            'transferModes' => TransferMode::options(),
        ];
    }

    /**
     * The available routes of the property with their prices, by name, for the Transfers tab and the booking form.
     *
     * @return list<TransferRouteData>
     */
    public function routes(Property $property): array
    {
        return array_values(TransferRoute::query()
            ->where('property_id', $property->id)
            ->where('active', true)
            ->with(['sharedChargeItem', 'privateChargeItem'])
            ->orderBy('name')
            ->get()
            ->map(fn (TransferRoute $route): TransferRouteData => TransferRouteData::fromModel($route))
            ->all());
    }
}
