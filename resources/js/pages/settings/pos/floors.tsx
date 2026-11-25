import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import type { PointerEvent } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    index as floorsIndex,
    store as storeFloor,
    update as updateFloor,
} from '@/routes/settings/pos/floors';
import {
    batch as batchTables,
    update as updateTable,
} from '@/routes/settings/pos/tables';

type Table = {
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

type Floor = {
    id: number;
    name: string;
    sortOrder: number;
    active: boolean;
    tables: Table[];
};

type Props = {
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    floors: Floor[];
    shapes: EnumOption[];
};

const GRID = 10;

function FloorCanvas({ floor }: { floor: Floor }) {
    const [positions, setPositions] = useState<
        Record<number, { x: number; y: number }>
    >({});
    const drag = useRef<{
        table: Table;
        startX: number;
        startY: number;
        originX: number;
        originY: number;
    } | null>(null);

    const place = (table: Table) => positions[table.id] ?? table;

    const down = (table: Table, event: PointerEvent<HTMLDivElement>) => {
        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = {
            table,
            startX: event.clientX,
            startY: event.clientY,
            originX: place(table).x,
            originY: place(table).y,
        };
    };

    const move = (event: PointerEvent<HTMLDivElement>) => {
        const current = drag.current;

        if (current === null) {
            return;
        }

        const snap = (value: number) =>
            Math.min(4000, Math.max(0, Math.round(value / GRID) * GRID));

        setPositions((all) => ({
            ...all,
            [current.table.id]: {
                x: snap(current.originX + event.clientX - current.startX),
                y: snap(current.originY + event.clientY - current.startY),
            },
        }));
    };

    const up = () => {
        const current = drag.current;
        drag.current = null;

        if (current === null) {
            return;
        }

        const next = positions[current.table.id];

        if (next === undefined) {
            return;
        }

        router.put(
            updateTable.url({ table: current.table.id }),
            {
                code: current.table.code,
                label: current.table.label,
                seats: current.table.seats,
                shape: current.table.shape,
                x: next.x,
                y: next.y,
                width: current.table.width,
                height: current.table.height,
                active: current.table.active,
            },
            { preserveScroll: true },
        );
    };

    return (
        <div className="relative h-96 overflow-auto rounded-md border bg-muted/30">
            {floor.tables.map((table) => {
                const at = place(table);

                return (
                    <div
                        key={table.id}
                        role="button"
                        tabIndex={0}
                        aria-label={`Table ${table.code}, ${table.seats} seats`}
                        onPointerDown={(event) => down(table, event)}
                        onPointerMove={move}
                        onPointerUp={up}
                        style={{
                            left: at.x,
                            top: at.y,
                            width: table.width,
                            height: table.height,
                        }}
                        className={`absolute flex cursor-grab touch-none flex-col items-center justify-center border bg-background text-sm shadow-sm select-none active:cursor-grabbing ${table.shape === 'round' ? 'rounded-full' : 'rounded-md'} ${table.active ? '' : 'opacity-50'}`}
                    >
                        <span className="font-medium">{table.code}</span>
                        <span className="text-xs text-muted-foreground">
                            {table.seats} seats
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

function AddTables({ floor }: { floor: Floor }) {
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
            <Field className="w-24">
                <FieldLabel>Prefix</FieldLabel>
                <Input
                    value={form.data.prefix}
                    onChange={(event) =>
                        form.setData('prefix', event.target.value)
                    }
                />
            </Field>
            <Field className="w-24">
                <FieldLabel>From</FieldLabel>
                <Input
                    type="number"
                    min={0}
                    value={form.data.start}
                    onChange={(event) =>
                        form.setData('start', event.target.value)
                    }
                />
            </Field>
            <Field className="w-24">
                <FieldLabel>To</FieldLabel>
                <Input
                    type="number"
                    min={0}
                    value={form.data.end}
                    onChange={(event) =>
                        form.setData('end', event.target.value)
                    }
                />
            </Field>
            <Field className="w-24">
                <FieldLabel>Seats</FieldLabel>
                <Input
                    type="number"
                    min={1}
                    value={form.data.seats}
                    onChange={(event) =>
                        form.setData('seats', event.target.value)
                    }
                />
            </Field>
            <Button type="submit" disabled={form.processing}>
                <Plus data-icon="inline-start" />
                Add tables
            </Button>
        </form>
    );
}

function RenameFloor({ floor }: { floor: Floor }) {
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
            <Field className="w-56">
                <FieldLabel>Floor name</FieldLabel>
                <Input
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <Field className="w-24">
                <FieldLabel>Order</FieldLabel>
                <Input
                    type="number"
                    min={0}
                    value={form.data.sort_order}
                    onChange={(event) =>
                        form.setData('sort_order', event.target.value)
                    }
                />
            </Field>
            <Button
                type="button"
                variant="outline"
                onClick={() => form.setData('active', !form.data.active)}
            >
                {form.data.active ? 'Active' : 'Switched off'}
            </Button>
            <Button
                type="submit"
                variant="secondary"
                disabled={form.processing}
            >
                Save floor
            </Button>
        </form>
    );
}

export default function PosFloors({ outlets, outlet, floors }: Props) {
    const newFloor = useForm({ name: '' });

    return (
        <PosSettingsShell activeTab="floors">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        options={outlets}
                        value={String(outlet?.id ?? '')}
                        onValueChange={(value) =>
                            router.get(
                                floorsIndex.url(undefined, {
                                    query: { outlet: value },
                                }),
                            )
                        }
                        aria-label="Outlet"
                    />
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Floors</CardTitle>
                        <CardDescription>
                            Dining areas and tables for a pay-later outlet. Drag
                            a table to place it; it saves when you let go.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="flex items-end gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (outlet !== null) {
                                    newFloor.post(
                                        storeFloor.url({ outlet: outlet.id }),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => newFloor.reset(),
                                        },
                                    );
                                }
                            }}
                        >
                            <Field className="w-64">
                                <FieldLabel>New floor</FieldLabel>
                                <Input
                                    placeholder="Indoor"
                                    value={newFloor.data.name}
                                    onChange={(event) =>
                                        newFloor.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Button
                                type="submit"
                                disabled={
                                    outlet === null || newFloor.processing
                                }
                            >
                                <Plus data-icon="inline-start" />
                                Add floor
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {floors.map((floor) => (
                    <Card key={floor.id}>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                {floor.name}
                                {!floor.active && (
                                    <Badge variant="secondary">Off</Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <RenameFloor floor={floor} />
                            <AddTables floor={floor} />
                            <FloorCanvas floor={floor} />
                        </CardContent>
                    </Card>
                ))}
            </div>
        </PosSettingsShell>
    );
}

PosFloors.layout = {
    breadcrumbs: posSettingsBreadcrumbs('floors'),
};
