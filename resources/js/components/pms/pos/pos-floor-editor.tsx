import { router, useForm } from '@inertiajs/react';
import {
    Circle,
    LayoutGrid,
    Move,
    RectangleHorizontal,
    Square,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    store as storeFloor,
    update as updateFloor,
} from '@/routes/settings/pos/floors';
import {
    batch as batchTables,
    store as storeTable,
    update as updateTable,
} from '@/routes/settings/pos/tables';
import { unlessRefused } from './pos-unless-refused';

export type EditorTable = {
    id: number;
    code: string;
    label: string | null;
    seats: number;
    shape: string;
    x: number;
    y: number;
    width: number;
    height: number;
    active: boolean;
};

export type EditorFloor = {
    id: number;
    name: string;
    sortOrder: number;
    active: boolean;
    tables: EditorTable[];
};

const GRID = 10;
const STAGE_PAD = 32;

const SHAPE_META: Record<string, { icon: LucideIcon; description: string }> = {
    square: {
        icon: Square,
        description: 'Standard 2 to 4 seat dining table.',
    },
    round: {
        icon: Circle,
        description: 'Circular table for cafes or family groups.',
    },
    rect: {
        icon: RectangleHorizontal,
        description: 'Longer table for 6+ guests or booths.',
    },
};

const SIZE_PRESETS = [
    { label: 'Compact', width: '80', height: '80' },
    { label: 'Standard', width: '100', height: '100' },
    { label: 'Wide', width: '150', height: '100' },
    { label: 'Banquet', width: '180', height: '110' },
];

function snap(value: number): number {
    return Math.min(4000, Math.max(0, Math.round(value / GRID) * GRID));
}

function savePosition(table: EditorTable, x: number, y: number) {
    router.put(
        updateTable.url({ table: table.id }),
        {
            code: table.code,
            label: table.label,
            seats: table.seats,
            shape: table.shape,
            x,
            y,
            width: table.width,
            height: table.height,
            active: table.active,
        },
        { preserveScroll: true },
    );
}

/**
 * Architectural chair markers placed around the outside edge of a table according to its shape and seat count.
 */
function TableChairs({
    seats,
    shape,
    active,
    dragging,
}: {
    seats: number;
    shape: string;
    active: boolean;
    dragging: boolean;
}) {
    const visibleSeats = Math.max(1, Math.min(10, seats));
    const chairClass = cn(
        'pointer-events-none transition-colors motion-reduce:transition-none',
        dragging
            ? 'bg-primary/70'
            : active
              ? 'bg-muted-foreground/35 group-hover/table:bg-primary/60'
              : 'bg-muted-foreground/20',
    );

    if (shape === 'round') {
        return (
            <>
                {Array.from({ length: visibleSeats }, (_, index) => {
                    const angle =
                        (2 * Math.PI * index) / visibleSeats - Math.PI / 2;
                    const leftPct = 50 + Math.cos(angle) * 58;
                    const topPct = 50 + Math.sin(angle) * 58;
                    const deg = (angle * 180) / Math.PI + 90;

                    return (
                        <span
                            key={index}
                            aria-hidden="true"
                            style={{
                                left: `${leftPct}%`,
                                top: `${topPct}%`,
                                transform: `translate(-50%, -50%) rotate(${deg}deg)`,
                            }}
                            className={cn(
                                'absolute h-1.5 w-4 rounded-full',
                                chairClass,
                            )}
                        />
                    );
                })}
            </>
        );
    }

    // Square tables with 4 seats get 1 chair per side; rectangles & larger counts distribute along top/bottom + ends.
    let topCount = 0;
    let bottomCount = 0;
    let leftCount = 0;
    let rightCount = 0;

    if (visibleSeats === 1) {
        topCount = 1;
    } else if (visibleSeats === 2) {
        topCount = 1;
        bottomCount = 1;
    } else if (shape === 'square' && visibleSeats === 4) {
        topCount = 1;
        bottomCount = 1;
        leftCount = 1;
        rightCount = 1;
    } else if (visibleSeats >= 6) {
        leftCount = 1;
        rightCount = 1;
        const remaining = visibleSeats - 2;
        topCount = Math.ceil(remaining / 2);
        bottomCount = Math.floor(remaining / 2);
    } else {
        topCount = Math.ceil(visibleSeats / 2);
        bottomCount = Math.floor(visibleSeats / 2);
    }

    return (
        <>
            {topCount > 0 && (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-3 -top-2.5 flex justify-evenly"
                >
                    {Array.from({ length: topCount }, (_, idx) => (
                        <span
                            key={idx}
                            className={cn('h-1.5 w-5 rounded-full', chairClass)}
                        />
                    ))}
                </span>
            )}
            {bottomCount > 0 && (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-3 -bottom-2.5 flex justify-evenly"
                >
                    {Array.from({ length: bottomCount }, (_, idx) => (
                        <span
                            key={idx}
                            className={cn('h-1.5 w-5 rounded-full', chairClass)}
                        />
                    ))}
                </span>
            )}
            {leftCount > 0 && (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-3 -left-2.5 flex flex-col justify-evenly"
                >
                    {Array.from({ length: leftCount }, (_, idx) => (
                        <span
                            key={idx}
                            className={cn('h-5 w-1.5 rounded-full', chairClass)}
                        />
                    ))}
                </span>
            )}
            {rightCount > 0 && (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-3 -right-2.5 flex flex-col justify-evenly"
                >
                    {Array.from({ length: rightCount }, (_, idx) => (
                        <span
                            key={idx}
                            className={cn('h-5 w-1.5 rounded-full', chairClass)}
                        />
                    ))}
                </span>
            )}
        </>
    );
}

export function FloorCanvas({
    floor,
    onEditTable,
}: {
    floor: EditorFloor;
    onEditTable: (table: EditorTable) => void;
}) {
    const [positions, setPositions] = useState<
        Record<number, { x: number; y: number }>
    >({});
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [focusedId, setFocusedId] = useState<number | null>(null);
    const drag = useRef<{
        table: EditorTable;
        startX: number;
        startY: number;
        originX: number;
        originY: number;
        moved: boolean;
    } | null>(null);

    const place = (table: EditorTable) => positions[table.id] ?? table;

    const down = (table: EditorTable, event: PointerEvent<HTMLDivElement>) => {
        event.currentTarget.setPointerCapture(event.pointerId);
        setFocusedId(table.id);
        drag.current = {
            table,
            startX: event.clientX,
            startY: event.clientY,
            originX: place(table).x,
            originY: place(table).y,
            moved: false,
        };
    };

    const move = (event: PointerEvent<HTMLDivElement>) => {
        const current = drag.current;

        if (current === null) {
            return;
        }

        const dx = event.clientX - current.startX;
        const dy = event.clientY - current.startY;

        if (Math.abs(dx) > 4 || Math.abs(dy) > 4) {
            current.moved = true;
            setDraggingId(current.table.id);
        }

        if (!current.moved) {
            return;
        }

        setPositions((all) => ({
            ...all,
            [current.table.id]: {
                x: snap(current.originX + dx),
                y: snap(current.originY + dy),
            },
        }));
    };

    const up = () => {
        const current = drag.current;
        drag.current = null;
        setDraggingId(null);

        if (current === null) {
            return;
        }

        if (!current.moved) {
            onEditTable(current.table);

            return;
        }

        const next = positions[current.table.id];

        if (next === undefined) {
            return;
        }

        savePosition(current.table, next.x, next.y);
    };

    const onKeyDown = (
        table: EditorTable,
        event: KeyboardEvent<HTMLDivElement>,
    ) => {
        const at = place(table);

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onEditTable(table);

            return;
        }

        const delta: Record<string, [number, number]> = {
            ArrowUp: [0, -GRID],
            ArrowDown: [0, GRID],
            ArrowLeft: [-GRID, 0],
            ArrowRight: [GRID, 0],
        };
        const step = delta[event.key];

        if (!step) {
            return;
        }

        event.preventDefault();
        const nextX = snap(at.x + step[0]);
        const nextY = snap(at.y + step[1]);
        setPositions((all) => ({
            ...all,
            [table.id]: { x: nextX, y: nextY },
        }));
        savePosition(table, nextX, nextY);
    };

    if (floor.tables.length === 0) {
        return (
            <div className="p-6">
                <EmptyState
                    icon={LayoutGrid}
                    title="No tables on this floor yet"
                    description="Use Batch add or Add table above to place tables on this floor."
                />
            </div>
        );
    }

    const maxX = Math.max(
        640,
        ...floor.tables.map((table) => place(table).x + table.width),
    );
    const maxY = Math.max(
        340,
        ...floor.tables.map((table) => place(table).y + table.height),
    );
    const activeTable =
        floor.tables.find((table) => table.id === (draggingId ?? focusedId)) ??
        null;
    const activePos = activeTable ? place(activeTable) : null;

    return (
        <div className="flex flex-col">
            <div
                className="relative h-112 overflow-auto bg-muted/20"
                style={{
                    backgroundImage:
                        'radial-gradient(var(--border) 1.25px, transparent 1.25px)',
                    backgroundSize: '20px 20px',
                }}
            >
                <div
                    className="relative"
                    style={{
                        width: maxX + STAGE_PAD * 2,
                        height: maxY + STAGE_PAD * 2,
                    }}
                >
                    {floor.tables.map((table) => {
                        const at = place(table);
                        const isDragging = draggingId === table.id;

                        return (
                            <div
                                key={table.id}
                                role="button"
                                tabIndex={0}
                                aria-label={`Table ${table.code}, ${table.seats} seats${table.active ? '' : ', inactive'}, press Enter to edit or arrow keys to move`}
                                onFocus={() => setFocusedId(table.id)}
                                onPointerDown={(event) => down(table, event)}
                                onPointerMove={move}
                                onPointerUp={up}
                                onKeyDown={(event) => onKeyDown(table, event)}
                                style={{
                                    left: at.x + STAGE_PAD,
                                    top: at.y + STAGE_PAD,
                                    width: table.width,
                                    height: table.height,
                                }}
                                className={cn(
                                    'group/table absolute flex cursor-grab touch-none flex-col items-center justify-center gap-1 border-2 px-2 text-center transition-colors select-none focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none active:cursor-grabbing motion-reduce:transition-none',
                                    table.shape === 'round'
                                        ? 'rounded-full'
                                        : 'rounded-2xl',
                                    table.active
                                        ? 'border-border bg-card text-card-foreground shadow-xs hover:border-primary/70'
                                        : 'border-dashed border-border bg-muted/60 text-muted-foreground opacity-75 hover:border-foreground/40',
                                    isDragging &&
                                        'z-10 border-primary shadow-md ring-3 ring-primary/25',
                                )}
                            >
                                <TableChairs
                                    seats={table.seats}
                                    shape={table.shape}
                                    active={table.active}
                                    dragging={isDragging}
                                />

                                <span className="font-heading text-base leading-none font-bold tracking-tight">
                                    {table.code}
                                </span>

                                {table.label && (
                                    <span className="max-w-full truncate text-xs text-muted-foreground">
                                        {table.label}
                                    </span>
                                )}

                                <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                                    <Users
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    {table.seats}
                                </span>

                                {!table.active && (
                                    <span className="text-xs font-medium text-muted-foreground">
                                        Off
                                    </span>
                                )}

                                {isDragging && (
                                    <span className="pointer-events-none absolute -top-8 rounded-md bg-foreground px-2 py-0.5 text-xs font-medium whitespace-nowrap text-background tabular-nums shadow-xs">
                                        x: {at.x}, y: {at.y}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            <footer className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t bg-muted/30 px-4 py-2.5 text-xs text-muted-foreground">
                <div className="flex flex-wrap items-center gap-4">
                    <span className="inline-flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-3 rounded-sm border-2 border-border bg-card"
                        />
                        Active table
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="size-3 rounded-sm border-2 border-dashed border-border bg-muted"
                        />
                        Inactive
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <Move className="size-3.5" aria-hidden="true" />
                        Drag or use arrow keys (10px snap)
                    </span>
                </div>
                <div className="tabular-nums">
                    {activeTable && activePos ? (
                        <span>
                            {draggingId === activeTable.id
                                ? 'Moving'
                                : 'Selected'}{' '}
                            <strong className="font-medium text-foreground">
                                {activeTable.code}
                            </strong>{' '}
                            · x: {activePos.x}, y: {activePos.y}
                        </span>
                    ) : (
                        <span>Click any table to edit details</span>
                    )}
                </div>
            </footer>
        </div>
    );
}

export function FloorFormDialog({
    outletId,
    floor,
    onClose,
}: {
    outletId: number;
    floor: EditorFloor | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: floor?.name ?? '',
        sort_order: String(floor?.sortOrder ?? 0),
        active: floor?.active ?? true,
    });

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => !open && onClose()}
            title={floor ? `Edit ${floor.name}` : 'Add floor'}
            description="Name a dining area such as Main Dining, Terrace, or Bar."
            errors={form.errors}
            processing={form.processing}
            submitLabel={floor ? 'Save floor' : 'Add floor'}
            onSubmit={() => {
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (floor) {
                    form.put(updateFloor.url({ floor: floor.id }), options);
                } else {
                    form.post(storeFloor.url({ outlet: outletId }), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="floor-name">Floor name</FieldLabel>
                <Input
                    id="floor-name"
                    maxLength={80}
                    placeholder="e.g. Main Dining"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            {floor !== null && (
                <>
                    <Field data-invalid={!!form.errors.sort_order}>
                        <FieldLabel htmlFor="floor-order">
                            Sort order
                        </FieldLabel>
                        <Input
                            id="floor-order"
                            type="number"
                            min={0}
                            className="tabular-nums"
                            value={form.data.sort_order}
                            onChange={(event) =>
                                form.setData('sort_order', event.target.value)
                            }
                            aria-invalid={!!form.errors.sort_order}
                        />
                        <FieldError>{form.errors.sort_order}</FieldError>
                    </Field>
                    <div className="flex items-center justify-between gap-4 rounded-xl border bg-muted/20 p-3.5">
                        <div className="flex flex-col gap-0.5">
                            <FieldLabel htmlFor="floor-active">
                                Active on register
                            </FieldLabel>
                            <FieldDescription>
                                Turn off to hide this area from the POS floor
                                board without deleting its tables.
                            </FieldDescription>
                        </div>
                        <Switch
                            id="floor-active"
                            checked={form.data.active}
                            onCheckedChange={(checked) =>
                                form.setData('active', checked)
                            }
                        />
                    </div>
                </>
            )}
        </FormOverlay>
    );
}

export function BatchTablesDialog({
    floor,
    onClose,
}: {
    floor: EditorFloor;
    onClose: () => void;
}) {
    const form = useForm({ prefix: 'A', start: '1', end: '12', seats: '4' });

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Batch add tables to ${floor.name}`}
            description="Generate a numbered range of tables at once (for example A1 to A12)."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Create tables"
            onSubmit={() => {
                form.post(batchTables.url({ floor: floor.id }), {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                });
            }}
        >
            <div className="grid grid-cols-2 gap-3">
                <Field data-invalid={!!form.errors.prefix}>
                    <FieldLabel htmlFor="batch-prefix">Prefix</FieldLabel>
                    <Input
                        id="batch-prefix"
                        maxLength={10}
                        placeholder="e.g. A"
                        value={form.data.prefix}
                        onChange={(event) =>
                            form.setData(
                                'prefix',
                                event.target.value.toUpperCase(),
                            )
                        }
                        aria-invalid={!!form.errors.prefix}
                        autoFocus
                    />
                    <FieldError>{form.errors.prefix}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.seats}>
                    <FieldLabel htmlFor="batch-seats">
                        Seats per table
                    </FieldLabel>
                    <Input
                        id="batch-seats"
                        type="number"
                        min={1}
                        max={99}
                        className="tabular-nums"
                        value={form.data.seats}
                        onChange={(event) =>
                            form.setData('seats', event.target.value)
                        }
                        aria-invalid={!!form.errors.seats}
                    />
                    <FieldError>{form.errors.seats}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.start}>
                    <FieldLabel htmlFor="batch-start">From number</FieldLabel>
                    <Input
                        id="batch-start"
                        type="number"
                        min={0}
                        className="tabular-nums"
                        value={form.data.start}
                        onChange={(event) =>
                            form.setData('start', event.target.value)
                        }
                        aria-invalid={!!form.errors.start}
                    />
                    <FieldError>{form.errors.start}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.end}>
                    <FieldLabel htmlFor="batch-end">To number</FieldLabel>
                    <Input
                        id="batch-end"
                        type="number"
                        min={0}
                        className="tabular-nums"
                        value={form.data.end}
                        onChange={(event) =>
                            form.setData('end', event.target.value)
                        }
                        aria-invalid={!!form.errors.end}
                    />
                    <FieldError>{form.errors.end}</FieldError>
                </Field>
            </div>
        </FormOverlay>
    );
}

export function TableFormSheet({
    floorId,
    table,
    shapes,
    onClose,
}: {
    floorId: number;
    table: EditorTable | null;
    shapes: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        code: table?.code ?? '',
        label: table?.label ?? '',
        seats: String(table?.seats ?? 4),
        shape: table?.shape ?? shapes[0]?.value ?? 'square',
        x: String(table?.x ?? 20),
        y: String(table?.y ?? 20),
        width: String(table?.width ?? 100),
        height: String(table?.height ?? 100),
        active: table?.active ?? true,
    });

    const shapeChoices = shapes.map((shape) => {
        const meta = SHAPE_META[shape.value] ?? {
            icon: Square,
            description: 'Table footprint on the floor canvas.',
        };

        return {
            value: shape.value,
            label: shape.label,
            description: meta.description,
            icon: meta.icon,
        };
    });

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open
            onOpenChange={(open) => !open && onClose()}
            title={table ? `Edit table ${table.code}` : 'Add table'}
            description="Set the table number, seating capacity, footprint shape, and availability on the register."
            errors={form.errors}
            dirty={form.isDirty}
            processing={form.processing}
            submitLabel={table ? 'Save table' : 'Add table'}
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    label: data.label.trim() === '' ? null : data.label.trim(),
                    seats: Number(data.seats) || 1,
                    x: Number(data.x) || 0,
                    y: Number(data.y) || 0,
                    width: Number(data.width) || 100,
                    height: Number(data.height) || 100,
                }));
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (table) {
                    form.put(updateTable.url({ table: table.id }), options);
                } else {
                    form.post(storeTable.url({ floor: floorId }), options);
                }
            }}
        >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field data-invalid={!!form.errors.code}>
                    <FieldLabel htmlFor="table-code">Table code</FieldLabel>
                    <Input
                        id="table-code"
                        maxLength={20}
                        placeholder="e.g. A1 or VIP-1"
                        value={form.data.code}
                        onChange={(event) =>
                            form.setData(
                                'code',
                                event.target.value.toUpperCase(),
                            )
                        }
                        aria-invalid={!!form.errors.code}
                        autoFocus
                    />
                    <FieldDescription>
                        Short code shown on the floor plan and checks.
                    </FieldDescription>
                    <FieldError>{form.errors.code}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.seats}>
                    <FieldLabel htmlFor="table-seats">Seats</FieldLabel>
                    <Input
                        id="table-seats"
                        type="number"
                        min={1}
                        max={99}
                        className="tabular-nums"
                        value={form.data.seats}
                        onChange={(event) =>
                            form.setData('seats', event.target.value)
                        }
                        aria-invalid={!!form.errors.seats}
                    />
                    <FieldDescription>
                        Number of chairs rendered around the table.
                    </FieldDescription>
                    <FieldError>{form.errors.seats}</FieldError>
                </Field>
            </div>

            <Field data-invalid={!!form.errors.label}>
                <FieldLabel htmlFor="table-label">Label (optional)</FieldLabel>
                <Input
                    id="table-label"
                    maxLength={60}
                    placeholder="e.g. Window booth or Garden corner"
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                    aria-invalid={!!form.errors.label}
                />
                <FieldError>{form.errors.label}</FieldError>
            </Field>

            <ChoiceCards
                id="table-shape"
                name="shape"
                legend="Table shape"
                columns={3}
                choices={shapeChoices}
                value={form.data.shape}
                onValueChange={(value) => {
                    form.setData((data) => ({
                        ...data,
                        shape: value,
                        ...(value === 'rect' &&
                        data.width === '100' &&
                        data.height === '100'
                            ? { width: '150', height: '100' }
                            : {}),
                    }));
                }}
                error={form.errors.shape}
            />

            <div className="flex flex-col gap-3 rounded-xl border bg-muted/20 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-col gap-0.5">
                        <span className="text-sm font-medium text-foreground">
                            Canvas size
                        </span>
                        <span className="text-xs text-muted-foreground">
                            Pick a quick footprint or fine-tune in pixels.
                        </span>
                    </div>
                    <div className="flex flex-wrap justify-end gap-1.5">
                        {SIZE_PRESETS.map((preset) => {
                            const selected =
                                form.data.width === preset.width &&
                                form.data.height === preset.height;

                            return (
                                <Button
                                    key={preset.label}
                                    type="button"
                                    size="xs"
                                    variant={selected ? 'default' : 'outline'}
                                    onClick={() =>
                                        form.setData((data) => ({
                                            ...data,
                                            width: preset.width,
                                            height: preset.height,
                                        }))
                                    }
                                >
                                    {preset.label}
                                </Button>
                            );
                        })}
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Field data-invalid={!!form.errors.width}>
                        <FieldLabel htmlFor="table-width">
                            Width (px)
                        </FieldLabel>
                        <Input
                            id="table-width"
                            type="number"
                            min={40}
                            max={800}
                            className="tabular-nums"
                            value={form.data.width}
                            onChange={(event) =>
                                form.setData('width', event.target.value)
                            }
                            aria-invalid={!!form.errors.width}
                        />
                        <FieldError>{form.errors.width}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.height}>
                        <FieldLabel htmlFor="table-height">
                            Height (px)
                        </FieldLabel>
                        <Input
                            id="table-height"
                            type="number"
                            min={40}
                            max={800}
                            className="tabular-nums"
                            value={form.data.height}
                            onChange={(event) =>
                                form.setData('height', event.target.value)
                            }
                            aria-invalid={!!form.errors.height}
                        />
                        <FieldError>{form.errors.height}</FieldError>
                    </Field>
                </div>
            </div>

            <div className="flex items-center justify-between gap-4 rounded-xl border bg-card p-3.5">
                <div className="flex flex-col gap-0.5">
                    <FieldLabel htmlFor="table-active">
                        Active on floor board
                    </FieldLabel>
                    <FieldDescription>
                        Show this table on the POS register so staff can seat
                        guests here.
                    </FieldDescription>
                </div>
                <Switch
                    id="table-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
            </div>
        </FormOverlay>
    );
}
