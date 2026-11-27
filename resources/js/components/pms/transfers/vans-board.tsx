import { Bus, TriangleAlert } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { transferRuns } from '@/lib/transfer-runs';

type Row = App.Data.Transfers.TransferRunRowData;
type Vehicle = App.Data.Transfers.TransferVehicleData;
type Driver = App.Data.Transfers.TransferDriverData;

/**
 * The Vans view of the run sheet: one card per vehicle with its runs of the day (time, route, pax against seats,
 * driver), a warning when a run is over the seats or overlaps another run of the same van, and the transfers with no
 * van yet. Built from the run sheet rows; nothing is loaded. See docs/35-transfers/01-land-transfers.md#vans-board.
 */
export function VansBoard({
    rows,
    vehicles,
    drivers,
}: {
    rows: Row[];
    vehicles: Vehicle[];
    drivers: Driver[];
}) {
    const runs = transferRuns(rows, vehicles);
    const unassigned = rows.filter((row) => row.vehicleId === null);
    const shown = vehicles.filter(
        (vehicle) => vehicle.active || runs.has(vehicle.id),
    );
    const driverName = (id: number) =>
        drivers.find((driver) => driver.id === id)?.label ?? '—';

    if (shown.length === 0) {
        return (
            <EmptyState
                icon={Bus}
                title="No vehicles yet."
                description="Add your vans in Settings › Transfers › Vehicles."
            />
        );
    }

    return (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {shown.map((vehicle) => {
                const vehicleRuns = runs.get(vehicle.id) ?? [];

                return (
                    <Card key={vehicle.id} size="sm">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                {vehicle.name}
                                {vehicle.plate && (
                                    <span className="font-normal text-muted-foreground">
                                        {vehicle.plate}
                                    </span>
                                )}
                                {vehicle.seats !== null && (
                                    <Badge variant="outline">
                                        {vehicle.seats} seats
                                    </Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {vehicleRuns.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Free all day.
                                </p>
                            )}
                            {vehicleRuns.map((run) => (
                                <div
                                    key={run.key}
                                    className="flex flex-col gap-1 rounded-md border p-2"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-medium tabular-nums">
                                            {run.pickupTime}
                                            {run.endsAt && `–${run.endsAt}`}
                                        </span>
                                        <span
                                            className={
                                                run.overSeats
                                                    ? 'text-sm font-medium text-warning tabular-nums'
                                                    : 'text-sm tabular-nums'
                                            }
                                        >
                                            {run.pax}
                                            {run.seats !== null &&
                                                ` / ${run.seats}`}{' '}
                                            pax
                                        </span>
                                    </div>
                                    <span className="text-sm">
                                        {run.routeName}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {run.driverIds.length === 0
                                            ? 'No driver'
                                            : run.driverIds
                                                  .map(driverName)
                                                  .join(', ')}{' '}
                                        ·{' '}
                                        {run.transfers
                                            .map(
                                                (transfer) =>
                                                    transfer.guest ??
                                                    transfer.reference,
                                            )
                                            .join(', ')}
                                    </span>
                                    {run.clashesWith.length > 0 && (
                                        <span className="flex items-center gap-1 text-xs text-warning">
                                            <TriangleAlert className="size-3.5" />
                                            Overlaps another run of this van
                                        </span>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                );
            })}
            {unassigned.length > 0 && (
                <Card size="sm">
                    <CardHeader>
                        <CardTitle>No van yet</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-1 text-sm">
                        {unassigned.map((row) => (
                            <span key={row.id} className="tabular-nums">
                                {row.pickupTime} · {row.routeName} ·{' '}
                                {row.guest ?? row.reference} · {row.pax} pax
                            </span>
                        ))}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
