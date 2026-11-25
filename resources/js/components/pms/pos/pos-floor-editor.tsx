import { router, useForm } from '@inertiajs/react';
import { LayoutGrid, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { update as updateFloor } from '@/routes/settings/pos/floors';
import {
    batch as batchTables,
    store as storeTable,
    update as updateTable,
} from '@/routes/settings/pos/tables';

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

export function FloorCanvas({
    floor,
    onEditTable,
    onAddSingleTable,
}: {
    floor: EditorFloor;
    onEditTable: (table: EditorTable) => void;
    onAddSingleTable: () => void;
}) {
    const [positions, setPositions] = useState<
        Record<number, { x: number; y: number }>
    >({});
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
            <EmptyState
                icon={LayoutGrid}
                title="No tables on this floor"
                description="Add a single table or batch-create a range above."
                action={
                    <Button type="button" onClick={onAddSingleTable}>
                        <Plus data-icon="inline-start" />
                        Add table
                    </Button>
                }
            />
        );
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="text-xs text-muted-foreground">
                Click or press Enter on a table to edit its shape, label or
                size. Drag or use arrow keys to position on the 10px grid.
            </p>
            <div className="relative h-96 overflow-auto rounded-md border bg-muted/30">
                {floor.tables.map((table) => {
                    const at = place(table);

                    return (
                        <div
                            key={table.id}
                            role="button"
                            tabIndex={0}
                            aria-label={`Table ${table.code}, ${table.seats} seats, press Enter to edit or arrow keys to move`}
                            onPointerDown={(event) => down(table, event)}
                            onPointerMove={move}
                            onPointerUp={up}
                            onKeyDown={(event) => onKeyDown(table, event)}
                            style={{
                                left: at.x,
                                top: at.y,
                                width: table.width,
                                height: table.height,
                            }}
                            className={`absolute flex cursor-grab touch-none flex-col items-center justify-center border bg-background px-1 text-center text-sm shadow-sm select-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing ${table.shape === 'round' ? 'rounded-full' : 'rounded-md'} ${table.active ? '' : 'opacity-50'}`}
                        >
                            <span className="font-medium">{table.code}</span>
                            {table.label && (
                                <span className="max-w-full truncate text-[11px] text-muted-foreground">
                                    {table.label}
                                </span>
                            )}
                            <span className="text-xs text-muted-foreground">
                                {table.seats} seats
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
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

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(open) => !open && onClose()}
            title={table ? `Edit table ${table.code}` : 'Add table'}
            description="Configure table code, label, seating capacity, shape, and canvas dimensions."
            errors={form.errors}
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
                const options = { preserveScroll: true, onSuccess: onClose };

                if (table) {
                    form.put(updateTable.url({ table: table.id }), options);
                } else {
                    form.post(storeTable.url({ floor: floorId }), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.code}>
                <FieldLabel htmlFor="table-code">Code</FieldLabel>
                <Input
                    id="table-code"
                    maxLength={20}
                    placeholder="e.g. A1 or VIP-1"
                    value={form.data.code}
                    onChange={(event) =>
                        form.setData('code', event.target.value.toUpperCase())
                    }
                    aria-invalid={!!form.errors.code}
                    autoFocus
                />
                <FieldError>{form.errors.code}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.label}>
                <FieldLabel htmlFor="table-label">Label (optional)</FieldLabel>
                <Input
                    id="table-label"
                    maxLength={60}
                    placeholder="e.g. Window booth"
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                />
                <FieldError>{form.errors.label}</FieldError>
            </Field>
            <div className="grid grid-cols-2 gap-3">
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
                    />
                    <FieldError>{form.errors.seats}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.shape}>
                    <FieldLabel htmlFor="table-shape">Shape</FieldLabel>
                    <EnumSelect
                        id="table-shape"
                        name="shape"
                        options={shapes}
                        value={form.data.shape}
                        onValueChange={(value) => form.setData('shape', value)}
                    />
                    <FieldError>{form.errors.shape}</FieldError>
                </Field>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <Field data-invalid={!!form.errors.width}>
                    <FieldLabel htmlFor="table-width">Width (px)</FieldLabel>
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
                    />
                    <FieldError>{form.errors.width}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.height}>
                    <FieldLabel htmlFor="table-height">Height (px)</FieldLabel>
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
                    />
                    <FieldError>{form.errors.height}</FieldError>
                </Field>
            </div>
            <Field orientation="horizontal">
                <Switch
                    id="table-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="table-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}

export function AddTablesForm({
    floor,
    onAddSingle,
}: {
    floor: EditorFloor;
    onAddSingle: () => void;
}) {
    const form = useForm({ prefix: 'A', start: '1', end: '12', seats: '4' });

    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(batchTables.url({ floor: floor.id }), {
                    preserveScroll: true,
                });
            }}
        >
            <Field className="w-24" data-invalid={!!form.errors.prefix}>
                <FieldLabel htmlFor={`batch-prefix-${floor.id}`}>
                    Prefix
                </FieldLabel>
                <Input
                    id={`batch-prefix-${floor.id}`}
                    value={form.data.prefix}
                    onChange={(event) =>
                        form.setData('prefix', event.target.value)
                    }
                />
                <FieldError>{form.errors.prefix}</FieldError>
            </Field>
            <Field className="w-24" data-invalid={!!form.errors.start}>
                <FieldLabel htmlFor={`batch-start-${floor.id}`}>From</FieldLabel>
                <Input
                    id={`batch-start-${floor.id}`}
                    type="number"
                    min={0}
                    className="tabular-nums"
                    value={form.data.start}
                    onChange={(event) =>
                        form.setData('start', event.target.value)
                    }
                />
                <FieldError>{form.errors.start}</FieldError>
            </Field>
            <Field className="w-24" data-invalid={!!form.errors.end}>
                <FieldLabel htmlFor={`batch-end-${floor.id}`}>To</FieldLabel>
                <Input
                    id={`batch-end-${floor.id}`}
                    type="number"
                    min={0}
                    className="tabular-nums"
                    value={form.data.end}
                    onChange={(event) =>
                        form.setData('end', event.target.value)
                    }
                />
                <FieldError>{form.errors.end}</FieldError>
            </Field>
            <Field className="w-24" data-invalid={!!form.errors.seats}>
                <FieldLabel htmlFor={`batch-seats-${floor.id}`}>
                    Seats
                </FieldLabel>
                <Input
                    id={`batch-seats-${floor.id}`}
                    type="number"
                    min={1}
                    className="tabular-nums"
                    value={form.data.seats}
                    onChange={(event) =>
                        form.setData('seats', event.target.value)
                    }
                />
                <FieldError>{form.errors.seats}</FieldError>
            </Field>
            <Button
                type="submit"
                disabled={form.processing}
                focusableWhenDisabled
            >
                {form.processing ? (
                    <Spinner data-icon="inline-start" />
                ) : (
                    <Plus data-icon="inline-start" />
                )}
                Batch add
            </Button>
            <Button type="button" variant="outline" onClick={onAddSingle}>
                <Plus data-icon="inline-start" />
                Add single table
            </Button>
        </form>
    );
}

export function RenameFloorForm({ floor }: { floor: EditorFloor }) {
    const form = useForm({
        name: floor.name,
        sort_order: String(floor.sortOrder),
        active: floor.active,
    });

    return (
        <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(updateFloor.url({ floor: floor.id }), {
                    preserveScroll: true,
                });
            }}
        >
            <Field className="w-56" data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor={`floor-name-${floor.id}`}>
                    Floor name
                </FieldLabel>
                <Input
                    id={`floor-name-${floor.id}`}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field className="w-24" data-invalid={!!form.errors.sort_order}>
                <FieldLabel htmlFor={`floor-order-${floor.id}`}>
                    Order
                </FieldLabel>
                <Input
                    id={`floor-order-${floor.id}`}
                    type="number"
                    min={0}
                    className="tabular-nums"
                    value={form.data.sort_order}
                    onChange={(event) =>
                        form.setData('sort_order', event.target.value)
                    }
                />
                <FieldError>{form.errors.sort_order}</FieldError>
            </Field>
            <Field orientation="horizontal" className="pb-2">
                <Switch
                    id={`floor-active-${floor.id}`}
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor={`floor-active-${floor.id}`}>
                    Active
                </FieldLabel>
            </Field>
            <Button
                type="submit"
                variant="secondary"
                disabled={form.processing}
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Save floor
            </Button>
        </form>
    );
}
