import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { combo as saveCombo } from '@/routes/settings/pos/items';
import {
    store as storeSchedule,
    update as updateSchedule,
} from '@/routes/settings/pos/schedules';

export type ScheduleRow = {
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

export type ComboRow = {
    id: number;
    name: string;
    slots: {
        name: string;
        min: number;
        max: number;
        options: { variantId: number; upcharge: string }[];
    }[];
};

export type ComboVariantOption = EnumOption & { itemId?: number };

export const SCHEDULE_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export function scheduleDaysLabel(mask: number): string {
    if (mask === 127) {
        return 'Every day';
    }

    return SCHEDULE_DAYS.filter((_, index) => (mask & (1 << index)) !== 0).join(
        ', ',
    );
}

export function PosScheduleForm({
    outletId,
    row,
    categories,
    items,
    mealPeriods,
    onClose,
}: {
    outletId: number;
    row: ScheduleRow | null;
    categories: EnumOption[];
    items: EnumOption[];
    mealPeriods: EnumOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: row?.name ?? '',
        days_of_week: row?.daysOfWeek ?? 127,
        start_time: row?.startTime ?? '06:00',
        end_time: row?.endTime ?? '11:00',
        effective_from: row?.effectiveFrom ?? '',
        effective_until: row?.effectiveUntil ?? '',
        meal_period_id: row?.mealPeriodId ? String(row.mealPeriodId) : '',
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
            variant="sheet"
            open
            onOpenChange={(open) => !open && onClose()}
            title={row === null ? 'Add schedule' : 'Edit schedule'}
            description="Items named here are sold only inside this window. Items no schedule names are always on sale."
            errors={form.errors}
            dirty={form.isDirty}
            processing={form.processing}
            submitLabel="Save schedule"
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    effective_from: data.effective_from || null,
                    effective_until: data.effective_until || null,
                    meal_period_id: data.meal_period_id
                        ? Number(data.meal_period_id)
                        : null,
                }));
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
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            {mealPeriods.length > 0 && (
                <Field>
                    <FieldLabel htmlFor="schedule-meal-period">
                        Meal period (optional)
                    </FieldLabel>
                    <EnumSelect
                        id="schedule-meal-period"
                        name="meal_period_id"
                        options={mealPeriods}
                        value={form.data.meal_period_id}
                        onValueChange={(value) =>
                            form.setData('meal_period_id', value)
                        }
                        placeholder="Not tied to a meal period"
                    />
                </Field>
            )}
            <Field>
                <FieldLabel>Days</FieldLabel>
                <div className="flex flex-wrap gap-2">
                    {SCHEDULE_DAYS.map((day, index) => {
                        const active =
                            (form.data.days_of_week & (1 << index)) !== 0;

                        return (
                            <Button
                                key={day}
                                type="button"
                                size="sm"
                                aria-pressed={active}
                                variant={active ? 'default' : 'outline'}
                                onClick={() =>
                                    form.setData(
                                        'days_of_week',
                                        form.data.days_of_week ^ (1 << index),
                                    )
                                }
                            >
                                {day}
                            </Button>
                        );
                    })}
                </div>
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field>
                    <FieldLabel htmlFor="schedule-start">From</FieldLabel>
                    <Input
                        id="schedule-start"
                        type="time"
                        className="tabular-nums"
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
                        className="tabular-nums"
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
                        className="tabular-nums"
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
                        className="tabular-nums"
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
                            className="flex items-center gap-3 text-sm"
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
                            className="flex items-center gap-3 text-sm"
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

export function PosComboForm({
    item,
    combo,
    variants,
}: {
    item: EnumOption;
    combo: ComboRow | undefined;
    variants: ComboVariantOption[];
}) {
    const allowedVariants = variants.filter(
        (variant) =>
            variant.itemId === undefined ||
            variant.itemId !== Number(item.value),
    );
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
            {form.data.slots.length === 0 ? (
                <div className="rounded-lg border border-dashed bg-muted/20 p-4 text-sm text-muted-foreground">
                    No bundle parts yet for {item.label}. Add a part such as
                    Main or Drink, or save with no parts to treat it as a
                    regular item.
                </div>
            ) : (
                form.data.slots.map((slot, index) => (
                    <div
                        key={index}
                        className="flex flex-col gap-3 rounded-xl border bg-muted/20 p-4"
                    >
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div className="flex flex-1 flex-wrap items-end gap-3">
                                <Field className="min-w-44 flex-1">
                                    <FieldLabel htmlFor={`combo-part-${index}`}>
                                        Part name
                                    </FieldLabel>
                                    <Input
                                        id={`combo-part-${index}`}
                                        placeholder="e.g. Main dish"
                                        value={slot.name}
                                        onChange={(event) =>
                                            setSlot(index, {
                                                name: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field className="w-20">
                                    <FieldLabel htmlFor={`combo-min-${index}`}>
                                        Min
                                    </FieldLabel>
                                    <Input
                                        id={`combo-min-${index}`}
                                        type="number"
                                        min={0}
                                        className="tabular-nums"
                                        value={slot.min}
                                        onChange={(event) =>
                                            setSlot(index, {
                                                min: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field className="w-20">
                                    <FieldLabel htmlFor={`combo-max-${index}`}>
                                        Max
                                    </FieldLabel>
                                    <Input
                                        id={`combo-max-${index}`}
                                        type="number"
                                        min={1}
                                        className="tabular-nums"
                                        value={slot.max}
                                        onChange={(event) =>
                                            setSlot(index, {
                                                max: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="ml-auto max-sm:min-h-11"
                                aria-label={`Remove part ${slot.name || index + 1}`}
                                onClick={() =>
                                    form.setData(
                                        'slots',
                                        form.data.slots.filter(
                                            (_, at) => at !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 data-icon="inline-start" />
                                Remove part
                            </Button>
                        </div>

                        <div className="flex flex-col gap-2.5 border-t pt-3">
                            {slot.options.map((option, optionIndex) => (
                                <div
                                    key={optionIndex}
                                    className="grid grid-cols-1 items-end gap-2.5 sm:grid-cols-[minmax(0,1fr)_8rem_auto]"
                                >
                                    <Field>
                                        <FieldLabel
                                            htmlFor={`combo-opt-${index}-${optionIndex}`}
                                        >
                                            Option item
                                        </FieldLabel>
                                        <EnumSelect
                                            id={`combo-opt-${index}-${optionIndex}`}
                                            name={`slots_${index}_options_${optionIndex}`}
                                            options={allowedVariants}
                                            value={option.variant_id}
                                            onValueChange={(value) =>
                                                setSlot(index, {
                                                    options: slot.options.map(
                                                        (row, at) =>
                                                            at === optionIndex
                                                                ? {
                                                                      ...row,
                                                                      variant_id:
                                                                          value,
                                                                  }
                                                                : row,
                                                    ),
                                                })
                                            }
                                            placeholder="Pick an item"
                                        />
                                    </Field>
                                    <Field>
                                        <FieldLabel
                                            htmlFor={`combo-up-${index}-${optionIndex}`}
                                        >
                                            Upcharge
                                        </FieldLabel>
                                        <Input
                                            id={`combo-up-${index}-${optionIndex}`}
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
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : row,
                                                    ),
                                                })
                                            }
                                        />
                                    </Field>
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="max-sm:min-h-11"
                                            aria-label={`Remove option ${optionIndex + 1} from part ${slot.name || index + 1}`}
                                            onClick={() =>
                                                setSlot(index, {
                                                    options:
                                                        slot.options.filter(
                                                            (_, at) =>
                                                                at !==
                                                                optionIndex,
                                                        ),
                                                })
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            <div className="flex justify-end pt-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="max-sm:min-h-11"
                                    onClick={() =>
                                        setSlot(index, {
                                            options: [
                                                ...slot.options,
                                                {
                                                    variant_id: '',
                                                    upcharge: '0.00',
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus data-icon="inline-start" />
                                    Add option
                                </Button>
                            </div>
                        </div>
                    </div>
                ))
            )}

            <FieldError>{Object.values(form.errors)[0]}</FieldError>

            <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-4">
                <Button
                    type="button"
                    variant="outline"
                    className="max-sm:min-h-11"
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
                <Button
                    type="submit"
                    className="max-sm:min-h-11"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save combo
                </Button>
            </div>
        </form>
    );
}
