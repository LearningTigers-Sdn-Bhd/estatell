import { router } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { course, merge, move, split } from '@/routes/pos/orders';
import { split as splitLine } from '@/routes/pos/orders/lines';

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
                        <li key={line.id}>
                            <label className="flex items-center gap-3">
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
                                    {line.quantity} × {line.itemName} · course{' '}
                                    {line.courseNumber}
                                    {line.kitchenStatus === 'held' && ' · held'}
                                </span>
                            </label>
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
