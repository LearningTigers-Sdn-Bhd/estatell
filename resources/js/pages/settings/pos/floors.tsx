import { router } from '@inertiajs/react';
import {
    Grid2x2Plus,
    LayoutGrid,
    List,
    Pencil,
    Plus,
    Store,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    BatchTablesDialog,
    FloorCanvas,
    FloorFormDialog,
    TableFormSheet,
} from '@/components/pms/pos/pos-floor-editor';
import type {
    EditorFloor,
    EditorTable,
} from '@/components/pms/pos/pos-floor-editor';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { StatusBadge } from '@/components/pms/status-badge';
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { index as floorsIndex } from '@/routes/settings/pos/floors';

type Props = {
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    floors: EditorFloor[];
    shapes: EnumOption[];
};

export default function PosFloors({ outlets, outlet, floors, shapes }: Props) {
    const [selectedFloorId, setSelectedFloorId] = useState<number | null>(
        floors[0]?.id ?? null,
    );
    const [view, setView] = useState<'canvas' | 'list'>('canvas');
    const [floorDialog, setFloorDialog] = useState<{
        row: EditorFloor | null;
    } | null>(null);
    const [batchFloor, setBatchFloor] = useState<EditorFloor | null>(null);
    const [editingTable, setEditingTable] = useState<{
        floorId: number;
        table: EditorTable | null;
    } | null>(null);

    const activeFloor = useMemo(
        () =>
            floors.find((floor) => floor.id === selectedFloorId) ??
            floors[0] ??
            null,
        [floors, selectedFloorId],
    );

    const shapeLabels = useMemo(
        () => new Map(shapes.map((shape) => [shape.value, shape.label])),
        [shapes],
    );

    const activeSeats = useMemo(
        () =>
            activeFloor
                ? activeFloor.tables.reduce(
                      (sum, table) => sum + (table.active ? table.seats : 0),
                      0,
                  )
                : 0,
        [activeFloor],
    );

    const headerActions = (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {outlets.length > 1 && (
                <EnumSelect
                    name="outlet"
                    options={outlets}
                    value={String(outlet?.id ?? '')}
                    onValueChange={(value) =>
                        router.get(
                            floorsIndex.url(undefined, {
                                query: { outlet: value },
                            }),
                        )
                    }
                    icon={<Store aria-hidden />}
                    aria-label="Outlet"
                    className="w-48"
                />
            )}
            {activeFloor !== null ? (
                <>
                    <Button
                        type="button"
                        variant="outline"
                        className="max-sm:min-h-11"
                        disabled={outlet === null}
                        onClick={() => setFloorDialog({ row: null })}
                    >
                        <Plus data-icon="inline-start" />
                        Add floor
                    </Button>
                    <Button
                        type="button"
                        className="max-sm:min-h-11"
                        onClick={() =>
                            setEditingTable({
                                floorId: activeFloor.id,
                                table: null,
                            })
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add table
                    </Button>
                </>
            ) : (
                <Button
                    type="button"
                    className="max-sm:min-h-11"
                    disabled={outlet === null}
                    onClick={() => setFloorDialog({ row: null })}
                >
                    <Plus data-icon="inline-start" />
                    Add floor
                </Button>
            )}
        </div>
    );

    return (
        <PosSettingsShell activeTab="floors" actions={headerActions}>
            {outlet === null ? (
                <div className="rounded-xl border bg-card p-6">
                    <EmptyState
                        icon={Store}
                        title="No POS outlet available"
                        description="Enable built-in POS on at least one outlet under Setup to configure dining floors and tables."
                    />
                </div>
            ) : floors.length === 0 ? (
                <div className="rounded-xl border bg-card p-6">
                    <EmptyState
                        icon={LayoutGrid}
                        title="No floors yet"
                        description="Use Add floor above to create your first dining area such as Main Dining, Terrace, or Bar."
                    />
                </div>
            ) : (
                <div className="flex flex-col gap-5">
                    {floors.length > 1 && (
                        <div className="flex items-center gap-2 overflow-x-auto pb-1 lg:hidden">
                            {floors.map((floor) => {
                                const selected = activeFloor?.id === floor.id;

                                return (
                                    <Button
                                        key={floor.id}
                                        type="button"
                                        size="sm"
                                        variant={
                                            selected ? 'default' : 'outline'
                                        }
                                        className="shrink-0 max-sm:min-h-11"
                                        onClick={() =>
                                            setSelectedFloorId(floor.id)
                                        }
                                    >
                                        <span>{floor.name}</span>
                                        <span className="tabular-nums opacity-75">
                                            ({floor.tables.length})
                                        </span>
                                    </Button>
                                );
                            })}
                        </div>
                    )}

                    <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                        <aside
                            aria-label="Dining floors"
                            className="hidden rounded-xl border bg-card p-2 lg:sticky lg:top-4 lg:flex lg:flex-col lg:gap-1"
                        >
                            <div className="flex items-center justify-between px-2 py-1.5">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Floors ({floors.length})
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label="Add floor"
                                    onClick={() =>
                                        setFloorDialog({ row: null })
                                    }
                                >
                                    <Plus />
                                </Button>
                            </div>
                            {floors.map((floor) => {
                                const selected = activeFloor?.id === floor.id;
                                const seatTotal = floor.tables.reduce(
                                    (sum, table) =>
                                        sum + (table.active ? table.seats : 0),
                                    0,
                                );

                                return (
                                    <div
                                        key={floor.id}
                                        className={cn(
                                            'flex items-center gap-1 rounded-lg transition-colors motion-reduce:transition-none',
                                            selected
                                                ? 'bg-muted ring-1 ring-border'
                                                : 'hover:bg-muted/60',
                                        )}
                                    >
                                        <button
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() =>
                                                setSelectedFloorId(floor.id)
                                            }
                                            className="flex min-w-0 flex-1 flex-col gap-0.5 rounded-lg py-2 pr-1 pl-2.5 text-left focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            <div className="flex items-center gap-1.5">
                                                <span
                                                    className={cn(
                                                        'min-w-0 truncate text-sm',
                                                        selected
                                                            ? 'font-medium text-foreground'
                                                            : 'text-foreground/85',
                                                    )}
                                                >
                                                    {floor.name}
                                                </span>
                                                {!floor.active && (
                                                    <span className="text-xs text-muted-foreground">
                                                        Off
                                                    </span>
                                                )}
                                            </div>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {floor.tables.length}{' '}
                                                {floor.tables.length === 1
                                                    ? 'table'
                                                    : 'tables'}{' '}
                                                · {seatTotal}{' '}
                                                {seatTotal === 1
                                                    ? 'seat'
                                                    : 'seats'}
                                            </span>
                                        </button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            className="mr-1 shrink-0 text-muted-foreground hover:text-foreground"
                                            aria-label={`Edit floor ${floor.name}`}
                                            onClick={() =>
                                                setFloorDialog({ row: floor })
                                            }
                                        >
                                            <Pencil />
                                        </Button>
                                    </div>
                                );
                            })}
                        </aside>

                        {activeFloor !== null && (
                            <section className="overflow-hidden rounded-xl border bg-card">
                                <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3">
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="truncate font-heading text-base font-medium">
                                                {activeFloor.name}
                                            </h2>
                                            <Badge variant="outline">
                                                <span className="tabular-nums">
                                                    {activeFloor.tables.length}{' '}
                                                    {activeFloor.tables
                                                        .length === 1
                                                        ? 'table'
                                                        : 'tables'}{' '}
                                                    · {activeSeats}{' '}
                                                    {activeSeats === 1
                                                        ? 'seat'
                                                        : 'seats'}
                                                </span>
                                            </Badge>
                                            {!activeFloor.active && (
                                                <StatusBadge
                                                    status={{
                                                        value: 'inactive',
                                                        label: 'Inactive',
                                                        tone: 'neutral',
                                                    }}
                                                />
                                            )}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {outlet.name} · Sort order{' '}
                                            <span className="tabular-nums">
                                                {activeFloor.sortOrder}
                                            </span>
                                        </p>
                                    </div>

                                    <div className="ml-auto flex flex-wrap items-center justify-end gap-2">
                                        <Tabs
                                            value={view}
                                            onValueChange={(next) =>
                                                setView(
                                                    next as 'canvas' | 'list',
                                                )
                                            }
                                        >
                                            <TabsList>
                                                <TabsTrigger
                                                    value="canvas"
                                                    className="gap-1.5"
                                                >
                                                    <LayoutGrid aria-hidden />
                                                    Canvas
                                                </TabsTrigger>
                                                <TabsTrigger
                                                    value="list"
                                                    className="gap-1.5"
                                                >
                                                    <List aria-hidden />
                                                    List
                                                </TabsTrigger>
                                            </TabsList>
                                        </Tabs>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="max-sm:min-h-11"
                                            aria-label={`Edit floor ${activeFloor.name}`}
                                            onClick={() =>
                                                setFloorDialog({
                                                    row: activeFloor,
                                                })
                                            }
                                        >
                                            Edit floor
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            className="max-sm:min-h-11"
                                            aria-label={`Batch add tables to ${activeFloor.name}`}
                                            onClick={() =>
                                                setBatchFloor(activeFloor)
                                            }
                                        >
                                            <Grid2x2Plus data-icon="inline-start" />
                                            Batch add
                                        </Button>
                                    </div>
                                </header>

                                {view === 'canvas' ? (
                                    <FloorCanvas
                                        key={activeFloor.id}
                                        floor={activeFloor}
                                        onEditTable={(table) =>
                                            setEditingTable({
                                                floorId: activeFloor.id,
                                                table,
                                            })
                                        }
                                    />
                                ) : activeFloor.tables.length === 0 ? (
                                    <div className="p-6">
                                        <EmptyState
                                            icon={LayoutGrid}
                                            title="No tables on this floor yet"
                                            description="Use Batch add or Add table above to place tables on this floor."
                                        />
                                    </div>
                                ) : (
                                    <>
                                        <div className="hidden md:block">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow className="hover:bg-transparent">
                                                        <TableHead scope="col">
                                                            Table
                                                        </TableHead>
                                                        <TableHead scope="col">
                                                            Shape
                                                        </TableHead>
                                                        <TableHead
                                                            scope="col"
                                                            className="text-right"
                                                        >
                                                            Seats
                                                        </TableHead>
                                                        <TableHead scope="col">
                                                            Position
                                                        </TableHead>
                                                        <TableHead scope="col">
                                                            Status
                                                        </TableHead>
                                                        <TableHead
                                                            scope="col"
                                                            className="w-24 text-right"
                                                        >
                                                            <span className="sr-only">
                                                                Actions
                                                            </span>
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {activeFloor.tables.map(
                                                        (table) => (
                                                            <TableRow
                                                                key={table.id}
                                                            >
                                                                <TableCell>
                                                                    <div className="flex flex-col">
                                                                        <span className="font-medium text-foreground">
                                                                            {
                                                                                table.code
                                                                            }
                                                                        </span>
                                                                        {table.label && (
                                                                            <span className="text-xs text-muted-foreground">
                                                                                {
                                                                                    table.label
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </TableCell>
                                                                <TableCell>
                                                                    {shapeLabels.get(
                                                                        table.shape,
                                                                    ) ??
                                                                        table.shape}
                                                                </TableCell>
                                                                <TableCell className="text-right tabular-nums">
                                                                    {
                                                                        table.seats
                                                                    }
                                                                </TableCell>
                                                                <TableCell className="text-xs text-muted-foreground tabular-nums">
                                                                    x: {table.x}
                                                                    , y:{' '}
                                                                    {table.y} (
                                                                    {
                                                                        table.width
                                                                    }
                                                                    x
                                                                    {
                                                                        table.height
                                                                    }
                                                                    )
                                                                </TableCell>
                                                                <TableCell>
                                                                    <StatusBadge
                                                                        status={{
                                                                            value: table.active
                                                                                ? 'active'
                                                                                : 'inactive',
                                                                            label: table.active
                                                                                ? 'Active'
                                                                                : 'Inactive',
                                                                            tone: table.active
                                                                                ? 'success'
                                                                                : 'neutral',
                                                                        }}
                                                                    />
                                                                </TableCell>
                                                                <TableCell>
                                                                    <div className="flex justify-end">
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            aria-label={`Edit table ${table.code}`}
                                                                            onClick={() =>
                                                                                setEditingTable(
                                                                                    {
                                                                                        floorId:
                                                                                            activeFloor.id,
                                                                                        table,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            Edit
                                                                        </Button>
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )}
                                                </TableBody>
                                            </Table>
                                        </div>

                                        <div className="flex flex-col divide-y md:hidden">
                                            {activeFloor.tables.map((table) => (
                                                <div
                                                    key={table.id}
                                                    className="flex flex-col gap-2.5 p-4"
                                                >
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="flex min-w-0 flex-col gap-0.5">
                                                            <span className="font-medium text-foreground">
                                                                {table.code}
                                                                {table.label
                                                                    ? ` · ${table.label}`
                                                                    : ''}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                                {shapeLabels.get(
                                                                    table.shape,
                                                                ) ??
                                                                    table.shape}{' '}
                                                                · {table.seats}{' '}
                                                                {table.seats ===
                                                                1
                                                                    ? 'seat'
                                                                    : 'seats'}
                                                            </span>
                                                        </div>
                                                        <StatusBadge
                                                            status={{
                                                                value: table.active
                                                                    ? 'active'
                                                                    : 'inactive',
                                                                label: table.active
                                                                    ? 'Active'
                                                                    : 'Inactive',
                                                                tone: table.active
                                                                    ? 'success'
                                                                    : 'neutral',
                                                            }}
                                                        />
                                                    </div>
                                                    <div className="flex justify-end">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            className="min-h-11"
                                                            aria-label={`Edit table ${table.code}`}
                                                            onClick={() =>
                                                                setEditingTable(
                                                                    {
                                                                        floorId:
                                                                            activeFloor.id,
                                                                        table,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </>
                                )}
                            </section>
                        )}
                    </div>
                </div>
            )}

            {floorDialog !== null && outlet !== null && (
                <FloorFormDialog
                    key={floorDialog.row?.id ?? 'new'}
                    outletId={outlet.id}
                    floor={floorDialog.row}
                    onClose={() => setFloorDialog(null)}
                />
            )}

            {batchFloor !== null && (
                <BatchTablesDialog
                    key={batchFloor.id}
                    floor={batchFloor}
                    onClose={() => setBatchFloor(null)}
                />
            )}

            {editingTable !== null && (
                <TableFormSheet
                    key={
                        editingTable.table?.id ?? `new-${editingTable.floorId}`
                    }
                    floorId={editingTable.floorId}
                    table={editingTable.table}
                    shapes={shapes}
                    onClose={() => setEditingTable(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosFloors.layout = {
    breadcrumbs: posSettingsBreadcrumbs('floors'),
};
