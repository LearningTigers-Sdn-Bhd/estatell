import { Head, Link, router } from '@inertiajs/react';
import { Bus, ChevronLeft, ChevronRight } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { transfers as reservationTransfers } from '@/routes/reservations';
import { assign, index } from '@/routes/transfers';

type Row = App.Data.Transfers.TransferRunRowData;
type Vehicle = App.Data.Transfers.TransferVehicleData;
type Driver = App.Data.Transfers.TransferDriverData;

/** The Select value for "Not set". */
const NOT_SET = '';

/** A `Y-m-d` date moved by whole days, in UTC so it never shifts. */
function shiftDay(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

/** The available items, plus the one already picked when it has since been turned off. */
function pickOptions(
    items: { id: number; label: string; active: boolean }[],
    pickedId: number | null,
): EnumOption[] {
    return [
        { value: NOT_SET, label: 'Not set' },
        ...items
            .filter((item) => item.active || item.id === pickedId)
            .map((item) => ({ value: String(item.id), label: item.label })),
    ];
}

/**
 * Front desk › Transfers: every road transfer of one date, by pick-up time, with the vehicle and driver of each.
 * See docs/35-transfers/01-land-transfers.md#run-sheet.
 */
export default function TransfersIndex({
    date,
    today,
    rows,
    canAssign,
    vehicles,
    drivers,
}: {
    date: string;
    today: string;
    rows: Row[];
    canAssign: boolean;
    vehicles: Vehicle[];
    drivers: Driver[];
}) {
    const visit = (next: string) =>
        router.get(
            index.url(undefined, { query: { date: next } }),
            {},
            { preserveScroll: true },
        );
    const vehicleItems = vehicles.map((vehicle) => ({
        id: vehicle.id,
        label: vehicle.plate
            ? `${vehicle.name} · ${vehicle.plate}`
            : vehicle.name,
        active: vehicle.active,
    }));
    const driverItems = drivers.map((driver) => ({
        id: driver.id,
        label: driver.label,
        active: driver.active,
    }));
    const nameOf = (
        items: { id: number; label: string }[],
        id: number | null,
    ) => items.find((item) => item.id === id)?.label ?? '—';

    const save = (
        row: Row,
        vehicleId: number | null,
        driverId: number | null,
    ) =>
        router
            .optimistic<{ rows: Row[] }>((props) => ({
                rows: props.rows.map((item) =>
                    item.id === row.id
                        ? { ...item, vehicleId, driverId }
                        : item,
                ),
            }))
            .put(
                assign.url({ transfer: row.id }),
                {
                    transfer_vehicle_id: vehicleId,
                    transfer_driver_id: driverId,
                },
                { preserveScroll: true },
            );

    const pax = rows.reduce((sum, row) => sum + row.pax, 0);

    return (
        <>
            <Head title="Transfers" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Transfers"
                    description={
                        rows.length === 0
                            ? 'Road pick-ups and drop-offs of the day.'
                            : `${rows.length} ${rows.length === 1 ? 'transfer' : 'transfers'} · ${pax} ${pax === 1 ? 'guest' : 'guests'}`
                    }
                    actions={
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Previous day"
                                onClick={() => visit(shiftDay(date, -1))}
                            >
                                <ChevronLeft />
                            </Button>
                            <DatePicker
                                value={date}
                                onChange={(next) => next && visit(next)}
                                className="w-auto"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Next day"
                                onClick={() => visit(shiftDay(date, 1))}
                            >
                                <ChevronRight />
                            </Button>
                            {date !== today && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => visit(today)}
                                >
                                    Today
                                </Button>
                            )}
                        </div>
                    }
                />

                {rows.length === 0 ? (
                    <EmptyState
                        icon={Bus}
                        title="No transfers on this day."
                        description="Set a room's transfers on the Transfers tab of its booking."
                    />
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Pick-up</TableHead>
                                    <TableHead>Direction</TableHead>
                                    <TableHead>Route</TableHead>
                                    <TableHead>Guest</TableHead>
                                    <TableHead className="text-right">
                                        Pax
                                    </TableHead>
                                    <TableHead>Flight</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Vehicle</TableHead>
                                    <TableHead>Driver</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium tabular-nums">
                                            {row.pickupTime}
                                        </TableCell>
                                        <TableCell>
                                            {row.directionLabel}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <span>{row.routeName}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {row.place}
                                                    {row.minutes !== null &&
                                                        ` · ${row.minutes} min`}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <Link
                                                    href={reservationTransfers.url(
                                                        {
                                                            reservation:
                                                                row.reservationId,
                                                        },
                                                    )}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {row.guest ?? row.reference}
                                                </Link>
                                                {row.guest !== null && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {row.reference}
                                                    </span>
                                                )}
                                                {row.note && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {row.note}
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.pax}
                                        </TableCell>
                                        <TableCell>
                                            {row.flightNumber ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {row.modeLabel}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {canAssign ? (
                                                <EnumSelect
                                                    aria-label={`Vehicle for ${row.reference}`}
                                                    className="w-40"
                                                    options={pickOptions(
                                                        vehicleItems,
                                                        row.vehicleId,
                                                    )}
                                                    value={
                                                        row.vehicleId === null
                                                            ? NOT_SET
                                                            : String(
                                                                  row.vehicleId,
                                                              )
                                                    }
                                                    onValueChange={(value) =>
                                                        save(
                                                            row,
                                                            value === NOT_SET
                                                                ? null
                                                                : Number(value),
                                                            row.driverId,
                                                        )
                                                    }
                                                />
                                            ) : (
                                                nameOf(
                                                    vehicleItems,
                                                    row.vehicleId,
                                                )
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {canAssign ? (
                                                <EnumSelect
                                                    aria-label={`Driver for ${row.reference}`}
                                                    className="w-48"
                                                    options={pickOptions(
                                                        driverItems,
                                                        row.driverId,
                                                    )}
                                                    value={
                                                        row.driverId === null
                                                            ? NOT_SET
                                                            : String(
                                                                  row.driverId,
                                                              )
                                                    }
                                                    onValueChange={(value) =>
                                                        save(
                                                            row,
                                                            row.vehicleId,
                                                            value === NOT_SET
                                                                ? null
                                                                : Number(value),
                                                        )
                                                    }
                                                />
                                            ) : (
                                                nameOf(
                                                    driverItems,
                                                    row.driverId,
                                                )
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}

TransfersIndex.layout = {
    breadcrumbs: [{ title: 'Transfers', href: index() }],
};
