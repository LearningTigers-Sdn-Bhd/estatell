import { router } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { course, merge, move, split } from '@/routes/pos/orders';
import { place, split as splitLine } from '@/routes/pos/orders/lines';

type Order = App.Data.Pos.PosOrderData;

export type OtherCheck = { id: number; label: string };

/**
 * What a waiter does to a table check: hold or send a course, split a quantity, split lines onto a new check, move lines to
 * another check, or merge the whole check into another. The server decides what is allowed and says why when it is not.
 */
export function PosCheckActionsDialog({
    open,
    onClose,
    order,
    others,
}: {
    open: boolean;
    onClose: () => void;
    order: Order;
    others: OtherCheck[];
}) {
    const live = order.lines.filter(
        (line) => !line.isVoided && !line.isPackagingFee,
    );
    const [picked, setPicked] = useState<number[]>([]);
    const [target, setTarget] = useState('');
    const [courseNumber, setCourseNumber] = useState('2');
    const [splitLineId, setSplitLineId] = useState('');
    const [splitQty, setSplitQty] = useState('1');
    const seats = Array.from(
        new Set(
            live
                .map((line) => line.seatNumber)
                .filter((seat): seat is number => seat !== null),
        ),
    ).sort((a, b) => a - b);
    const options = others.map((row) => ({
        value: String(row.id),
        label: row.label,
    }));
    const post = (url: string, data: Record<string, unknown>) =>
        router.post(url, data as never, {
            preserveScroll: true,
            onSuccess: () => {
                setPicked([]);
                onClose();
            },
        });

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Check actions"
            description="Split, move or merge this check, or hold and send a course."
            errors={{}}
            processing={false}
            submitLabel="Close"
            onSubmit={onClose}
        >
            <Field>
                <FieldLabel>Lines</FieldLabel>
                <ul className="flex flex-col gap-2">
                    {live.map((line) => (
                        <li
                            key={line.id}
                            className="flex flex-wrap items-center gap-3"
                        >
                            <label className="flex flex-1 items-center gap-3">
                                <Checkbox
                                    checked={picked.includes(line.id)}
                                    onCheckedChange={(value) =>
                                        setPicked((all) =>
                                            value === true
                                                ? [...all, line.id]
                                                : all.filter(
                                                      (id) => id !== line.id,
                                                  ),
                                        )
                                    }
                                />
                                <span>
                                    {line.quantity} × {line.itemName}
                                    {line.kitchenStatus === 'held' && ' · held'}
                                </span>
                            </label>
                            <PlaceFields orderId={order.id} line={line} />
                        </li>
                    ))}
                </ul>
            </Field>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={picked.length === 0}
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
                                size="sm"
                                onClick={() =>
                                    post(split.url({ order: order.id }), {
                                        line_ids: live
                                            .filter(
                                                (line) =>
                                                    line.seatNumber === seat,
                                            )
                                            .map((line) => line.id),
                                    })
                                }
                            >
                                Seat {seat} to a new check
                            </Button>
                        ))}
                    </div>
                </Field>
            )}

            <Field>
                <FieldLabel>Move the picked lines to</FieldLabel>
                <div className="flex gap-2">
                    <EnumSelect
                        options={options}
                        value={target}
                        onValueChange={setTarget}
                        placeholder="Pick a check"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={picked.length === 0 || target === ''}
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
                        disabled={target === ''}
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

            <Field>
                <FieldLabel>Split a quantity</FieldLabel>
                <div className="flex gap-2">
                    <EnumSelect
                        options={live.map((line) => ({
                            value: String(line.id),
                            label: `${line.quantity} × ${line.itemName}`,
                        }))}
                        value={splitLineId}
                        onValueChange={setSplitLineId}
                        placeholder="Pick a line"
                    />
                    <Input
                        type="number"
                        min={1}
                        className="w-20"
                        value={splitQty}
                        onChange={(event) => setSplitQty(event.target.value)}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={splitLineId === ''}
                        onClick={() =>
                            post(
                                splitLine.url({
                                    order: order.id,
                                    line: Number(splitLineId),
                                }),
                                { quantity: Number(splitQty) || 1 },
                            )
                        }
                    >
                        Split off
                    </Button>
                </div>
            </Field>

            <Field>
                <FieldLabel>Course</FieldLabel>
                <div className="flex gap-2">
                    <Input
                        type="number"
                        min={2}
                        max={9}
                        className="w-20"
                        value={courseNumber}
                        onChange={(event) =>
                            setCourseNumber(event.target.value)
                        }
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            post(course.url({ order: order.id }), {
                                course: Number(courseNumber),
                                action: 'hold',
                            })
                        }
                    >
                        Hold
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            post(course.url({ order: order.id }), {
                                course: Number(courseNumber),
                                action: 'fire',
                            })
                        }
                    >
                        Send course
                    </Button>
                </div>
            </Field>
        </FormOverlay>
    );
}

type Line = App.Data.Pos.PosOrderLineData;

/** Course and seat of one line. A course can only change before the line is sent to the kitchen. */
function PlaceFields({ orderId, line }: { orderId: number; line: Line }) {
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
                className="w-16"
                disabled={!canCourse}
                value={courseValue}
                onChange={(event) => setCourseValue(event.target.value)}
            />
            <Input
                aria-label={`Seat for ${line.itemName}`}
                type="number"
                min={1}
                max={99}
                className="w-16"
                placeholder="Seat"
                value={seatValue}
                onChange={(event) => setSeatValue(event.target.value)}
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                    router.post(
                        place.url({ order: orderId, line: line.id }),
                        {
                            course: canCourse
                                ? Number(courseValue) || 1
                                : undefined,
                            seat: seatValue === '' ? 0 : Number(seatValue),
                        },
                        { preserveScroll: true },
                    )
                }
            >
                Place
            </Button>
        </span>
    );
}
