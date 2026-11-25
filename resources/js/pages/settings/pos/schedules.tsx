import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    index as schedulesIndex,
    store as storeSchedule,
    update as updateSchedule,
} from '@/routes/settings/pos/schedules';
import { combo as saveCombo } from '@/routes/settings/pos/items';

type Schedule = {
    id: number;
    name: string;
    daysOfWeek: number;
    startTime: string;
    endTime: string;
    effectiveFrom: string | null;
    effectiveUntil: string | null;
    mealPeriodId: number | null;
    active: boolean;
    categoryIds: number[];
    itemIds: number[];
};

type Combo = {
    id: number;
    name: string;
    slots: {
        name: string;
        min: number;
        max: number;
        options: { variantId: number; upcharge: string }[];
    }[];
};

type Props = {
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    schedules: Schedule[];
    categories: EnumOption[];
    items: EnumOption[];
    mealPeriods: EnumOption[];
    combos: Combo[];
    variants: EnumOption[];
};

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function daysLabel(mask: number): string {
    if (mask === 127) {
        return 'Every day';
    }

    return DAYS.filter((_, index) => (mask & (1 << index)) !== 0).join(', ');
}

function ScheduleForm({
    outletId,
    row,
    categories,
    items,
    onClose,
}: {
    outletId: number;
    row: Schedule | null;
    categories: EnumOption[];
    items: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        days_of_week: row?.daysOfWeek ?? 127,
        start_time: row?.startTime ?? '06:00',
        end_time: row?.endTime ?? '11:00',
        effective_from: row?.effectiveFrom ?? '',
        effective_until: row?.effectiveUntil ?? '',
        active: row?.active ?? true,
        category_ids: row?.categoryIds ?? ([] as number[]),
        item_ids: row?.itemIds ?? ([] as number[]),
    });

    const toggle = (key: 'category_ids' | 'item_ids', id: number) =>
        form.setData(
            key,
            form.data[key].includes(id)
                ? form.data[key].filter((value) => value !== id)
                : [...form.data[key], id],
        );

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => !open && onClose()}
            title={row === null ? 'Add schedule' : 'Edit schedule'}
            description="Items named here are sold only inside this window. Items no schedule names are always on sale."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save"
            onSubmit={() => {
                const options = {
                    preserveScroll: true,
                    onSuccess: onClose,
                };

                if (row === null) {
                    form.post(storeSchedule.url({ outlet: outletId }), options);
                } else {
                    form.put(updateSchedule.url({ schedule: row.id }), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="schedule-name">Name</FieldLabel>
                <Input
                    id="schedule-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
            </Field>
            <Field>
                <FieldLabel>Days</FieldLabel>
                <div className="flex flex-wrap gap-2">
                    {DAYS.map((day, index) => (
                        <Button
                            key={day}
                            type="button"
                            size="sm"
                            variant={
                                (form.data.days_of_week & (1 << index)) !== 0
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() =>
                                form.setData(
                                    'days_of_week',
                                    form.data.days_of_week ^ (1 << index),
                                )
                            }
                        >
                            {day}
                        </Button>
                    ))}
                </div>
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field>
                    <FieldLabel htmlFor="schedule-start">From</FieldLabel>
                    <Input
                        id="schedule-start"
                        type="time"
                        value={form.data.start_time}
                        onChange={(event) =>
                            form.setData('start_time', event.target.value)
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="schedule-end">Until</FieldLabel>
                    <Input
                        id="schedule-end"
                        type="time"
                        value={form.data.end_time}
                        onChange={(event) =>
                            form.setData('end_time', event.target.value)
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="schedule-from">Starts on</FieldLabel>
                    <Input
                        id="schedule-from"
                        type="date"
                        value={form.data.effective_from}
                        onChange={(event) =>
                            form.setData('effective_from', event.target.value)
                        }
                    />
                </Field>
                <Field>
                    <FieldLabel htmlFor="schedule-until">Ends on</FieldLabel>
                    <Input
                        id="schedule-until"
                        type="date"
                        value={form.data.effective_until}
                        onChange={(event) =>
                            form.setData('effective_until', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field>
                <FieldLabel>Categories</FieldLabel>
                <div className="flex flex-col gap-2">
                    {categories.map((category) => (
                        <label
                            key={category.value}
                            className="flex items-center gap-3"
                        >
                            <Checkbox
                                checked={form.data.category_ids.includes(
                                    Number(category.value),
                                )}
                                onCheckedChange={() =>
                                    toggle(
                                        'category_ids',
                                        Number(category.value),
                                    )
                                }
                            />
                            <span>{category.label}</span>
                        </label>
                    ))}
                </div>
            </Field>
            <Field>
                <FieldLabel>Items</FieldLabel>
                <div className="flex max-h-48 flex-col gap-2 overflow-y-auto">
                    {items.map((item) => (
                        <label
                            key={item.value}
                            className="flex items-center gap-3"
                        >
                            <Checkbox
                                checked={form.data.item_ids.includes(
                                    Number(item.value),
                                )}
                                onCheckedChange={() =>
                                    toggle('item_ids', Number(item.value))
                                }
                            />
                            <span>{item.label}</span>
                        </label>
                    ))}
                </div>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="schedule-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="schedule-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}

type SlotDraft = {
    name: string;
    min: string;
    max: string;
    options: { variant_id: string; upcharge: string }[];
};

function ComboForm({
    item,
    combo,
    variants,
}: {
    item: EnumOption;
    combo: Combo | undefined;
    variants: EnumOption[];
}) {
    const form = useForm({
        slots: (combo?.slots ?? []).map((slot): SlotDraft => ({
            name: slot.name,
            min: String(slot.min),
            max: String(slot.max),
            options: slot.options.map((option) => ({
                variant_id: String(option.variantId),
                upcharge: option.upcharge,
            })),
        })),
    });

    const setSlot = (index: number, change: Partial<SlotDraft>) =>
        form.setData(
            'slots',
            form.data.slots.map((slot, at) =>
                at === index ? { ...slot, ...change } : slot,
            ),
        );

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(saveCombo.url({ item: Number(item.value) }), {
                    preserveScroll: true,
                });
            }}
        >
            {form.data.slots.map((slot, index) => (
                <div
                    key={index}
                    className="flex flex-col gap-3 rounded-lg border p-3"
                >
                    <div className="flex flex-wrap items-end gap-3">
                        <Field className="w-56">
                            <FieldLabel>Part name</FieldLabel>
                            <Input
                                value={slot.name}
                                onChange={(event) =>
                                    setSlot(index, { name: event.target.value })
                                }
                            />
                        </Field>
                        <Field className="w-20">
                            <FieldLabel>Min</FieldLabel>
                            <Input
                                type="number"
                                min={0}
                                value={slot.min}
                                onChange={(event) =>
                                    setSlot(index, { min: event.target.value })
                                }
                            />
                        </Field>
                        <Field className="w-20">
                            <FieldLabel>Max</FieldLabel>
                            <Input
                                type="number"
                                min={1}
                                value={slot.max}
                                onChange={(event) =>
                                    setSlot(index, { max: event.target.value })
                                }
                            />
                        </Field>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                form.setData(
                                    'slots',
                                    form.data.slots.filter(
                                        (_, at) => at !== index,
                                    ),
                                )
                            }
                        >
                            Remove part
                        </Button>
                    </div>
                    {slot.options.map((option, optionIndex) => (
                        <div
                            key={optionIndex}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <EnumSelect
                                options={variants}
                                value={option.variant_id}
                                onValueChange={(value) =>
                                    setSlot(index, {
                                        options: slot.options.map((row, at) =>
                                            at === optionIndex
                                                ? { ...row, variant_id: value }
                                                : row,
                                        ),
                                    })
                                }
                                placeholder="Pick an item"
                            />
                            <Field className="w-28">
                                <FieldLabel>Upcharge</FieldLabel>
                                <Input
                                    inputMode="decimal"
                                    className="tabular-nums"
                                    value={option.upcharge}
                                    onChange={(event) =>
                                        setSlot(index, {
                                            options: slot.options.map(
                                                (row, at) =>
                                                    at === optionIndex
                                                        ? {
                                                              ...row,
                                                              upcharge:
                                                                  event.target
                                                                      .value,
                                                          }
                                                        : row,
                                            ),
                                        })
                                    }
                                />
                            </Field>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    setSlot(index, {
                                        options: slot.options.filter(
                                            (_, at) => at !== optionIndex,
                                        ),
                                    })
                                }
                            >
                                Remove
                            </Button>
                        </div>
                    ))}
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setSlot(index, {
                                    options: [
                                        ...slot.options,
                                        { variant_id: '', upcharge: '0.00' },
                                    ],
                                })
                            }
                        >
                            <Plus data-icon="inline-start" />
                            Add option
                        </Button>
                    </div>
                </div>
            ))}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        form.setData('slots', [
                            ...form.data.slots,
                            {
                                name: '',
                                min: '1',
                                max: '1',
                                options: [{ variant_id: '', upcharge: '0.00' }],
                            },
                        ])
                    }
                >
                    <Plus data-icon="inline-start" />
                    Add part
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save combo
                </Button>
            </div>
            {Object.values(form.errors)[0] && (
                <p role="alert" className="text-sm text-destructive">
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </form>
    );
}

export default function PosSchedules({
    outlets,
    outlet,
    schedules,
    categories,
    items,
    combos,
    variants,
}: Props) {
    const [editing, setEditing] = useState<{ row: Schedule | null } | null>(
        null,
    );
    const [comboItem, setComboItem] = useState('');
    const selected = items.find((item) => item.value === comboItem);

    return (
        <PosSettingsShell activeTab="schedules">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        options={outlets}
                        value={String(outlet?.id ?? '')}
                        onValueChange={(value) =>
                            router.get(
                                schedulesIndex.url(undefined, {
                                    query: { outlet: value },
                                }),
                            )
                        }
                        aria-label="Outlet"
                    />
                )}
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <CardTitle>Menu schedules</CardTitle>
                            <CardDescription>
                                Sell breakfast only in the morning or a happy
                                hour menu only in the evening.
                            </CardDescription>
                        </div>
                        <Button
                            disabled={outlet === null}
                            onClick={() => setEditing({ row: null })}
                        >
                            <Plus data-icon="inline-start" />
                            Add schedule
                        </Button>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {schedules.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No schedules. Every item is on sale all day.
                            </p>
                        )}
                        {schedules.map((schedule) => (
                            <div
                                key={schedule.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <div className="flex flex-col">
                                    <span className="font-medium">
                                        {schedule.name}{' '}
                                        {!schedule.active && (
                                            <Badge variant="secondary">
                                                Off
                                            </Badge>
                                        )}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {daysLabel(schedule.daysOfWeek)} ·{' '}
                                        {schedule.startTime} to{' '}
                                        {schedule.endTime} ·{' '}
                                        {schedule.categoryIds.length}{' '}
                                        categories, {schedule.itemIds.length}{' '}
                                        items
                                    </span>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setEditing({ row: schedule })
                                    }
                                >
                                    Edit
                                </Button>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Combos</CardTitle>
                        <CardDescription>
                            Pick an item and give it parts, such as a main and a
                            drink. The item&apos;s own price is the combo price,
                            and each pick can add an upcharge.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <EnumSelect
                            options={items}
                            value={comboItem}
                            onValueChange={setComboItem}
                            placeholder="Pick the combo item"
                        />
                        {selected !== undefined && (
                            <ComboForm
                                key={selected.value}
                                item={selected}
                                combo={combos.find(
                                    (combo) =>
                                        String(combo.id) === selected.value,
                                )}
                                variants={variants}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
            {editing !== null && outlet !== null && (
                <ScheduleForm
                    key={editing.row?.id ?? 'new'}
                    outletId={outlet.id}
                    row={editing.row}
                    categories={categories}
                    items={items}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosSchedules.layout = {
    breadcrumbs: posSettingsBreadcrumbs('schedules'),
};
