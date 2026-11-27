import { Head, Link, router } from '@inertiajs/react';
import { Bus, ChevronLeft, ChevronRight, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { ReportExportMenu } from '@/components/pms/reports/report-export-menu';
import { ReportLayoutSheet } from '@/components/pms/reports/report-layout-sheet';
import { VanRunDialog } from '@/components/pms/transfers/van-run-dialog';
import { VansBoard } from '@/components/pms/transfers/vans-board';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { transfers as reservationTransfers } from '@/routes/reservations';
import { assign, index, pdf, spreadsheet } from '@/routes/transfers';

type Row = App.Data.Transfers.TransferRunRowData;
type Vehicle = App.Data.Transfers.TransferVehicleData;
type Driver = App.Data.Transfers.TransferDriverData;
type Layout = App.Data.Reports.ReportLayoutData;

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
 * Export Transfer Manifest prints or downloads the same transfers for the drivers, with the saved column layout. Ticked
 * transfers go on one van run with Put on a van, and the Vans view shows each van's runs. See #vans-board too.
 * See docs/35-transfers/01-land-transfers.md#run-sheet.
 */
export default function TransfersIndex({
    date,
    today,
    rows,
    canAssign,
    canEditColumns,
    layout,
    orientationOptions,
    vehicles,
    drivers,
}: {
    date: string;
    today: string;
    rows: Row[];
    canAssign: boolean;
    canEditColumns: boolean;
    layout: Layout;
    orientationOptions: EnumOption[];
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
    const [layoutDraft, setLayoutDraft] = useState<Layout | null>(null);
    const [view, setView] = useState<'list' | 'vans'>('list');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [runOpen, setRunOpen] = useState(false);
    const picked = rows.filter((row) => selected.has(row.id));
    const toggleRow = (id: number, on: boolean) =>
        setSelected((current) => {
            const next = new Set(current);

            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

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
                            {canEditColumns && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setLayoutDraft(layout)}
                                >
                                    <Settings2 data-icon="inline-start" />
                                    Columns
                                </Button>
                            )}
                            {rows.length > 0 && (
                                <ReportExportMenu
                                    report="transfer-manifest"
                                    label="Export Transfer Manifest"
                                    dates={{ date }}
                                    sort="pickup"
                                    rowKeys={[]}
                                    pdfUrl={pdf.url()}
                                    spreadsheetUrl={spreadsheet.url()}
                                />
                            )}
                        </div>
                    }
                />

                {rows.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                        <ToggleGroup
                            variant="outline"
                            spacing={0}
                            aria-label="View"
                            value={[view]}
                            onValueChange={(values) => {
                                if (values[0]) {
                                    setView(values[0] as 'list' | 'vans');
                                }
                            }}
                        >
                            <ToggleGroupItem value="list">List</ToggleGroupItem>
                            <ToggleGroupItem value="vans">Vans</ToggleGroupItem>
                        </ToggleGroup>
                        {view === 'list' && canAssign && picked.length > 0 && (
                            <>
                                <span className="text-sm text-muted-foreground">
                                    {picked.length} selected ·{' '}
                                    {picked.reduce(
                                        (sum, row) => sum + row.pax,
                                        0,
                                    )}{' '}
                                    pax
                                </span>
                                <Button
                                    type="button"
                                    onClick={() => setRunOpen(true)}
                                >
                                    <Bus data-icon="inline-start" />
                                    Put on a van
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setSelected(new Set())}
                                >
                                    Clear
                                </Button>
                            </>
                        )}
                    </div>
                )}

                {rows.length > 0 && view === 'vans' ? (
                    <VansBoard
                        rows={rows}
                        vehicles={vehicles}
                        drivers={drivers}
                    />
                ) : rows.length === 0 ? (
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
                                    {canAssign && (
                                        <TableHead className="w-10">
                                            <Checkbox
                                                aria-label="Select every transfer"
                                                checked={
                                                    picked.length ===
                                                    rows.length
                                                }
                                                indeterminate={
                                                    picked.length > 0 &&
                                                    picked.length < rows.length
                                                }
                                                onCheckedChange={(on) =>
                                                    setSelected(
                                                        on
                                                            ? new Set(
                                                                  rows.map(
                                                                      (row) =>
                                                                          row.id,
                                                                  ),
                                                              )
                                                            : new Set(),
                                                    )
                                                }
                                            />
                                        </TableHead>
                                    )}
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
                                        {canAssign && (
                                            <TableCell>
                                                <Checkbox
                                                    aria-label={`Select ${row.reference}`}
                                                    checked={selected.has(
                                                        row.id,
                                                    )}
                                                    onCheckedChange={(on) =>
                                                        toggleRow(row.id, on)
                                                    }
                                                />
                                            </TableCell>
                                        )}
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

            {canAssign && runOpen && (
                <VanRunDialog
                    open
                    onOpenChange={setRunOpen}
                    transferIds={picked.map((row) => row.id)}
                    pax={picked.reduce((sum, row) => sum + row.pax, 0)}
                    seatsOf={(id) =>
                        vehicles.find((vehicle) => vehicle.id === id)?.seats ??
                        null
                    }
                    vehicleOptions={pickOptions(vehicleItems, null)}
                    driverOptions={pickOptions(driverItems, null)}
                    onDone={() => setSelected(new Set())}
                />
            )}

            {canEditColumns && layoutDraft && (
                <ReportLayoutSheet
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setLayoutDraft(null);
                        }
                    }}
                    report="transfer-manifest"
                    reportLabel="Transfer manifest"
                    saved={layout}
                    draft={layoutDraft}
                    onDraftChange={setLayoutDraft}
                    orientations={orientationOptions}
                    dates={{ date }}
                    onDone={() => setLayoutDraft(null)}
                    description="Choose the columns of the Transfer manifest, in the order they show, and how each aligns. Columns change the PDF, Excel and CSV. The run sheet on screen stays the same."
                />
            )}
        </>
    );
}

TransfersIndex.layout = {
    breadcrumbs: [{ title: 'Transfers', href: index() }],
};
