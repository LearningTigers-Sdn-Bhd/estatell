import { router } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    course,
    merge,
    move,
    split,
    table as transferTableRoute,
} from '@/routes/pos/orders';
import { place, split as splitLine } from '@/routes/pos/orders/lines';
import { qr as tableQr } from '@/routes/pos/tables';
import { unlessRefused } from './pos-unless-refused';

type Order = App.Data.Pos.PosOrderData;
type Line = App.Data.Pos.PosOrderLineData;

export type OtherCheck = { id: number; label: string };

/**
 * Waiter check actions sheet: transfer table, hold/send courses, split quantity, split/move/merge checks, and seat/course assignment.
 */
export function PosCheckActionsDialog({
    open,
    onClose,
    order,
    others,
    vacantTables = [],
}: {
    open: boolean;
    onClose: () => void;
    order: Order;
    others: OtherCheck[];
    vacantTables?: EnumOption[];
}) {
    const live = order.lines.filter(
        (line) => !line.isVoided && !line.isPackagingFee,
    );
    const splittable = live.filter(
        (line) =>
            Number(line.quantity) > 1 &&
            !line.isWeighed &&
            !line.isComboPart &&
            Number(line.discountAmount) === 0 &&
            !line.isEntitlementCovered,
    );
    const [picked, setPicked] = useState<number[]>([]);
    const [target, setTarget] = useState('');
    const [targetTable, setTargetTable] = useState('');
    const [splitLineId, setSplitLineId] = useState('');
    const [splitQty, setSplitQty] = useState('1');
    const [busy, setBusy] = useState(false);

    const seats = Array.from(
        new Set(
            live
                .map((line) => line.seatNumber)
                .filter((seat): seat is number => seat !== null),
        ),
    ).sort((a, b) => a - b);

    const courses = Array.from(
        new Set(
            live
                .map((line) => line.courseNumber)
                .filter((number) => number >= 2),
        ),
    ).sort((a, b) => a - b);

    const options = others.map((row) => ({
        value: String(row.id),
        label: row.label,
    }));

    const post = (
        url: string,
        data: Record<string, unknown>,
        closeAfter = true,
    ) => {
        if (busy) {
            return;
        }

        router.post(url, data as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: unlessRefused(() => {
                setPicked([]);
                if (closeAfter) {
                    onClose();
                }
            }),
        });
    };

    return (
        <Sheet open={open} onOpenChange={(value) => !value && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Check actions</SheetTitle>
                    <SheetDescription>
                        Transfer table, split, move or merge this check, or hold
                        and send courses.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-col gap-5 p-4">
                    {order.tableId !== null && (
                        <Field>
                            <FieldLabel>Guest ordering</FieldLabel>
                            <FieldDescription>
                                Print a QR slip so guests at {order.tableCode}{' '}
                                can order from their phone.
                            </FieldDescription>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 self-start"
                                disabled={busy}
                                onClick={() =>
                                    router.post(
                                        tableQr.url({ table: order.tableId! }),
                                    )
                                }
                            >
                                Show table QR
                            </Button>
                        </Field>
                    )}
                    {vacantTables.length > 0 && (
                        <Field>
                            <FieldLabel htmlFor="transfer-table-select">
                                Transfer to table
                            </FieldLabel>
                            <div className="flex gap-2">
                                <EnumSelect
                                    id="transfer-table-select"
                                    name="pos_table_id"
                                    options={vacantTables}
                                    value={targetTable}
                                    onValueChange={setTargetTable}
                                    placeholder="Pick a vacant table"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={busy || targetTable === ''}
                                    onClick={() =>
                                        post(
                                            transferTableRoute.url({
                                                order: order.id,
                                            }),
                                            {
                                                pos_table_id:
                                                    Number(targetTable),
                                            },
                                        )
                                    }
                                >
                                    Transfer
                                </Button>
                            </div>
                        </Field>
                    )}

                    <Field>
                        <FieldLabel>Lines (course &amp; seat)</FieldLabel>
                        <FieldDescription>
                            Pick lines to split or move, or set each line&apos;s
                            course and seat.
                        </FieldDescription>
                        <ul className="flex flex-col gap-2">
                            {live.map((line) => (
                                <li
                                    key={line.id}
                                    className="flex flex-wrap items-center gap-3 rounded-lg border p-2.5"
                                >
                                    <label className="flex min-w-0 flex-1 items-center gap-3">
                                        <Checkbox
                                            checked={picked.includes(line.id)}
                                            onCheckedChange={(value) =>
                                                setPicked((all) =>
                                                    value === true
                                                        ? [...all, line.id]
                                                        : all.filter(
                                                              (id) =>
                                                                  id !==
                                                                  line.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <span className="truncate">
                                            {Number(line.quantity)} ×{' '}
                                            {line.itemName}
                                            {line.kitchenStatus === 'held' &&
                                                ' · held'}
                                        </span>
                                    </label>
                                    <PlaceFields
                                        orderId={order.id}
                                        line={line}
                                        busy={busy}
                                        onPost={post}
                                    />
                                </li>
                            ))}
                        </ul>
                    </Field>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={busy || picked.length === 0}
                            onClick={() =>
                                post(split.url({ order: order.id }), {
                                    line_ids: picked,
                                })
                            }
                        >
                            Split to a new check
                        </Button>
                    </div>

                    {seats.length > 0 && (
                        <Field>
                            <FieldLabel>Split by seat</FieldLabel>
                            <div className="flex flex-wrap gap-2">
                                {seats.map((seat) => (
                                    <Button
                                        key={seat}
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                        disabled={busy}
                                        onClick={() =>
                                            post(
                                                split.url({ order: order.id }),
                                                {
                                                    line_ids: live
                                                        .filter(
                                                            (line) =>
                                                                line.seatNumber ===
                                                                seat,
                                                        )
                                                        .map((line) => line.id),
                                                },
                                            )
                                        }
                                    >
                                        Seat {seat} to a new check
                                    </Button>
                                ))}
                            </div>
                        </Field>
                    )}

                    {options.length > 0 && (
                        <Field>
                            <FieldLabel htmlFor="move-check-select">
                                Move or merge into check
                            </FieldLabel>
                            <div className="flex flex-wrap gap-2">
                                <EnumSelect
                                    id="move-check-select"
                                    name="target_order_id"
                                    options={options}
                                    value={target}
                                    onValueChange={setTarget}
                                    placeholder="Pick a check"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={
                                        busy ||
                                        picked.length === 0 ||
                                        target === ''
                                    }
                                    onClick={() =>
                                        post(move.url({ order: order.id }), {
                                            target_order_id: Number(target),
                                            line_ids: picked,
                                        })
                                    }
                                >
                                    Move
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={busy || target === ''}
                                    onClick={() =>
                                        post(merge.url({ order: order.id }), {
                                            target_order_id: Number(target),
                                        })
                                    }
                                >
                                    Merge all
                                </Button>
                            </div>
                        </Field>
                    )}

                    {splittable.length > 0 && (
                        <Field>
                            <FieldLabel htmlFor="split-line-select">
                                Split a quantity
                            </FieldLabel>
                            <div className="flex flex-wrap gap-2">
                                <EnumSelect
                                    id="split-line-select"
                                    name="split_line_id"
                                    options={splittable.map((line) => ({
                                        value: String(line.id),
                                        label: `${Number(line.quantity)} × ${line.itemName}`,
                                    }))}
                                    value={splitLineId}
                                    onValueChange={setSplitLineId}
                                    placeholder="Pick a line"
                                />
                                <Input
                                    type="number"
                                    min={1}
                                    aria-label="Quantity to split off"
                                    className="h-11 w-20 tabular-nums"
                                    value={splitQty}
                                    onChange={(event) =>
                                        setSplitQty(event.target.value)
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={busy || splitLineId === ''}
                                    onClick={() =>
                                        post(
                                            splitLine.url({
                                                order: order.id,
                                                line: Number(splitLineId),
                                            }),
                                            {
                                                quantity: Number(splitQty) || 1,
                                            },
                                        )
                                    }
                                >
                                    Split off
                                </Button>
                            </div>
                        </Field>
                    )}

                    {courses.length > 0 && (
                        <Field>
                            <FieldLabel>Hold or send courses</FieldLabel>
                            <div className="flex flex-col gap-2">
                                {courses.map((number) => {
                                    const courseLines = live.filter(
                                        (line) => line.courseNumber === number,
                                    );
                                    const heldCount = courseLines.filter(
                                        (line) => line.kitchenStatus === 'held',
                                    ).length;

                                    return (
                                        <div
                                            key={number}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2.5"
                                        >
                                            <span className="text-sm font-medium">
                                                Course {number} (
                                                {courseLines.length} item
                                                {courseLines.length === 1
                                                    ? ''
                                                    : 's'}
                                                )
                                            </span>
                                            <div className="flex gap-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="min-h-11"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        post(
                                                            course.url({
                                                                order: order.id,
                                                            }),
                                                            {
                                                                course: number,
                                                                action: 'hold',
                                                            },
                                                        )
                                                    }
                                                >
                                                    Hold Course {number}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    className="min-h-11"
                                                    disabled={
                                                        busy || heldCount === 0
                                                    }
                                                    onClick={() =>
                                                        post(
                                                            course.url({
                                                                order: order.id,
                                                            }),
                                                            {
                                                                course: number,
                                                                action: 'fire',
                                                            },
                                                        )
                                                    }
                                                >
                                                    Send Course {number}
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </Field>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

/** Course and seat of one line. A course can only change before the line is sent to the kitchen. */
function PlaceFields({
    orderId,
    line,
    busy,
    onPost,
}: {
    orderId: number;
    line: Line;
    busy: boolean;
    onPost: (
        url: string,
        data: Record<string, unknown>,
        closeAfter?: boolean,
    ) => void;
}) {
    const [courseValue, setCourseValue] = useState(String(line.courseNumber));
    const [seatValue, setSeatValue] = useState(String(line.seatNumber ?? ''));
    const canCourse =
        line.kitchenStatus === 'draft' || line.kitchenStatus === 'held';

    return (
        <span className="flex items-center gap-2">
            <Input
                aria-label={`Course for ${line.itemName}`}
                type="number"
                min={1}
                max={9}
                className="h-11 w-16 tabular-nums"
                disabled={!canCourse || busy}
                value={courseValue}
                onChange={(event) => setCourseValue(event.target.value)}
            />
            <Input
                aria-label={`Seat for ${line.itemName}`}
                type="number"
                min={1}
                max={99}
                className="h-11 w-16 tabular-nums"
                placeholder="Seat"
                disabled={busy}
                value={seatValue}
                onChange={(event) => setSeatValue(event.target.value)}
            />
            <Button
                type="button"
                variant="outline"
                className="min-h-11"
                disabled={busy}
                onClick={() =>
                    onPost(
                        place.url({ order: orderId, line: line.id }),
                        {
                            course: canCourse
                                ? Number(courseValue) || 1
                                : undefined,
                            seat: seatValue === '' ? 0 : Number(seatValue),
                        },
                        false,
                    )
                }
            >
                Save
            </Button>
        </span>
    );
}
