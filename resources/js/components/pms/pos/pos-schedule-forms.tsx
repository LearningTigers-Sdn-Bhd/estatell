import { useForm } from '@inertiajs/react';
import { Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormError } from '@/components/pms/form-error';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import type { MultiComboboxOption } from '@/components/pms/multi-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { combo as saveCombo } from '@/routes/settings/pos/items';
import {
    store as storeSchedule,
    update as updateSchedule,
} from '@/routes/settings/pos/schedules';
import { unlessRefused } from './pos-unless-refused';

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

const DAY_PRESETS = [
    { label: 'Every day', mask: 127 },
    { label: 'Weekdays', mask: 31 },
    { label: 'Weekends', mask: 96 },
] as const;

export function scheduleDaysLabel(mask: number): string {
    if (mask === 127) {
        return 'Every day';
    }

    if (mask === 31) {
        return 'Weekdays';
    }

    if (mask === 96) {
        return 'Weekends';
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
    categories: MultiComboboxOption[];
    items: MultiComboboxOption[];
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
        category_ids: (row?.categoryIds ?? []).map(String),
        item_ids: (row?.itemIds ?? []).map(String),
    });

    const targetCount =
        form.data.category_ids.length + form.data.item_ids.length;
    const isOvernight =
        form.data.start_time !== '' &&
        form.data.end_time !== '' &&
        form.data.end_time < form.data.start_time;

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open
            onOpenChange={(open) => !open && onClose()}
            title={row === null ? 'Add menu schedule' : 'Edit menu schedule'}
            description="Items or categories named in a schedule are sold only inside its window. Everything else stays available all day."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle={
                row === null
                    ? 'Discard this new schedule?'
                    : 'Discard changes to this schedule?'
            }
            processing={form.processing}
            submitLabel={row === null ? 'Create schedule' : 'Save schedule'}
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    effective_from: data.effective_from || null,
                    effective_until: data.effective_until || null,
                    meal_period_id: data.meal_period_id
                        ? Number(data.meal_period_id)
                        : null,
                    category_ids: data.category_ids.map(Number),
                    item_ids: data.item_ids.map(Number),
                }));
                const options = {
                    preserveScroll: true,
                    onSuccess: unlessRefused(onClose),
                };

                if (row === null) {
                    form.post(storeSchedule.url({ outlet: outletId }), options);
                } else {
                    form.put(updateSchedule.url({ schedule: row.id }), options);
                }
            }}
        >
            <FieldSet>
                <FieldLegend>Schedule details</FieldLegend>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.name}>
                        <FieldLabel htmlFor="schedule-name">
                            Schedule name
                        </FieldLabel>
                        <Input
                            id="schedule-name"
                            maxLength={100}
                            placeholder="e.g. Breakfast menu, Happy hour"
                            required
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
                        <Field data-invalid={!!form.errors.meal_period_id}>
                            <FieldLabel htmlFor="schedule-meal-period">
                                Meal period (optional)
                            </FieldLabel>
                            <EnumSelect
                                id="schedule-meal-period"
                                name="meal_period_id"
                                options={mealPeriods}
                                allLabel="Not tied to a meal period"
                                value={form.data.meal_period_id}
                                onValueChange={(value) =>
                                    form.setData('meal_period_id', value)
                                }
                                placeholder="Not tied to a meal period"
                            />
                            <FieldError>
                                {form.errors.meal_period_id}
                            </FieldError>
                        </Field>
                    )}
                </div>

                <Field
                    orientation="horizontal"
                    className="items-start rounded-lg border bg-muted/20 p-3"
                >
                    <Switch
                        id="schedule-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                        className="mt-0.5"
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="schedule-active">
                            Schedule active
                        </FieldLabel>
                        <FieldDescription>
                            Turn off to pause this time restriction so its items
                            return to normal availability.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend>Days and hours</FieldLegend>
                <Field data-invalid={!!form.errors.days_of_week}>
                    <FieldLabel>Active days</FieldLabel>
                    <div className="flex flex-wrap gap-1.5">
                        {DAY_PRESETS.map((preset) => {
                            const matched =
                                form.data.days_of_week === preset.mask;

                            return (
                                <Button
                                    key={preset.label}
                                    type="button"
                                    size="sm"
                                    variant={matched ? 'secondary' : 'ghost'}
                                    aria-pressed={matched}
                                    onClick={() =>
                                        form.setData(
                                            'days_of_week',
                                            preset.mask,
                                        )
                                    }
                                >
                                    {preset.label}
                                </Button>
                            );
                        })}
                    </div>
                    <div className="flex flex-wrap gap-2 pt-1">
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
                                    className="min-w-12 max-sm:min-h-11"
                                    onClick={() =>
                                        form.setData(
                                            'days_of_week',
                                            form.data.days_of_week ^
                                                (1 << index),
                                        )
                                    }
                                >
                                    {day}
                                </Button>
                            );
                        })}
                    </div>
                    <FieldError>{form.errors.days_of_week}</FieldError>
                </Field>

                <div className="grid grid-cols-2 gap-3">
                    <Field data-invalid={!!form.errors.start_time}>
                        <FieldLabel htmlFor="schedule-start">From</FieldLabel>
                        <Input
                            id="schedule-start"
                            type="time"
                            required
                            className="tabular-nums"
                            value={form.data.start_time}
                            onChange={(event) =>
                                form.setData('start_time', event.target.value)
                            }
                            aria-invalid={!!form.errors.start_time}
                        />
                        <FieldError>{form.errors.start_time}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.end_time}>
                        <FieldLabel htmlFor="schedule-end">Until</FieldLabel>
                        <Input
                            id="schedule-end"
                            type="time"
                            required
                            className="tabular-nums"
                            value={form.data.end_time}
                            onChange={(event) =>
                                form.setData('end_time', event.target.value)
                            }
                            aria-invalid={!!form.errors.end_time}
                        />
                        <FieldError>{form.errors.end_time}</FieldError>
                    </Field>
                </div>
                <p className="text-xs text-muted-foreground">
                    {isOvernight
                        ? 'This window runs past midnight into the next morning.'
                        : 'Overnight windows are supported (for example 22:00 until 02:00).'}
                </p>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.effective_from}>
                        <FieldLabel htmlFor="schedule-from">
                            Starts on date (optional)
                        </FieldLabel>
                        <Input
                            id="schedule-from"
                            type="date"
                            className="tabular-nums"
                            value={form.data.effective_from}
                            onChange={(event) =>
                                form.setData(
                                    'effective_from',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.effective_from}
                        />
                        <FieldError>{form.errors.effective_from}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.effective_until}>
                        <FieldLabel htmlFor="schedule-until">
                            Ends on date (optional)
                        </FieldLabel>
                        <Input
                            id="schedule-until"
                            type="date"
                            className="tabular-nums"
                            value={form.data.effective_until}
                            onChange={(event) =>
                                form.setData(
                                    'effective_until',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.effective_until}
                        />
                        <FieldError>{form.errors.effective_until}</FieldError>
                    </Field>
                </div>
                <p className="text-xs text-muted-foreground">
                    Leave start and end dates blank for a schedule that runs
                    year-round.
                </p>
            </FieldSet>

            <Separator />

            <FieldSet>
                <FieldLegend className="flex items-center gap-2">
                    <span>Categories and items controlled</span>
                    <Badge
                        variant="outline"
                        className="font-normal tabular-nums"
                    >
                        {targetCount} selected
                    </Badge>
                </FieldLegend>
                <FieldDescription>
                    Pick whole categories, specific items, or both. At least one
                    category or item is required.
                </FieldDescription>

                <Field data-invalid={!!form.errors.category_ids}>
                    <FieldLabel htmlFor="schedule-categories">
                        Categories
                    </FieldLabel>
                    <MultiCombobox
                        id="schedule-categories"
                        name="category_ids"
                        items={categories}
                        value={form.data.category_ids}
                        onValueChange={(value) =>
                            form.setData('category_ids', value)
                        }
                        placeholder="Search and select categories"
                        emptyLabel="No matching categories."
                        disabled={categories.length === 0}
                    />
                    <FieldError>{form.errors.category_ids}</FieldError>
                </Field>

                <Field data-invalid={!!form.errors.item_ids}>
                    <FieldLabel htmlFor="schedule-items">
                        Individual items
                    </FieldLabel>
                    <MultiCombobox
                        id="schedule-items"
                        name="item_ids"
                        items={items}
                        value={form.data.item_ids}
                        onValueChange={(value) =>
                            form.setData('item_ids', value)
                        }
                        placeholder="Search and select items"
                        emptyLabel="No matching items."
                        disabled={items.length === 0}
                    />
                    <FieldError>{form.errors.item_ids}</FieldError>
                </Field>

                {targetCount === 0 && (
                    <p className="text-xs text-warning-text">
                        Select at least one category or item above so this
                        schedule can be saved.
                    </p>
                )}
            </FieldSet>
        </FormOverlay>
    );
}

type SlotDraft = {
    name: string;
    min: string;
    max: string;
    options: { variant_id: string; upcharge: string }[];
};

function slotRuleBadge(minRaw: string, maxRaw: string): string {
    const min = Math.max(0, Number(minRaw) || 0);
    const max = Math.max(1, Number(maxRaw) || 1);

    if (min === 0) {
        return `Optional · up to ${max}`;
    }

    if (min === max) {
        return `Required · pick ${min}`;
    }

    return `Required · pick ${min} to ${max}`;
}

export function PosComboForm({
    item,
    combo,
    currency,
    variants,
    onDirtyChange,
}: {
    item: EnumOption;
    combo: ComboRow | undefined;
    currency: App.Data.Property.CurrencyData | null;
    variants: ComboVariantOption[];
    onDirtyChange?: (dirty: boolean) => void;
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

    useEffect(() => {
        onDirtyChange?.(form.isDirty);

        return () => onDirtyChange?.(false);
    }, [form.isDirty, onDirtyChange]);

    const setSlot = (index: number, change: Partial<SlotDraft>) =>
        form.setData(
            'slots',
            form.data.slots.map((slot, at) =>
                at === index ? { ...slot, ...change } : slot,
            ),
        );

    const addPart = () =>
        form.setData('slots', [
            ...form.data.slots,
            {
                name: '',
                min: '1',
                max: '1',
                options: [{ variant_id: '', upcharge: '0.00' }],
            },
        ]);

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            aria-label={`Configure combo parts for ${item.label}`}
            className="flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(saveCombo.url({ item: Number(item.value) }), {
                    preserveScroll: true,
                    onSuccess: () => form.setDefaults(),
                });
            }}
        >
            <FormError errors={form.errors} />

            {form.data.slots.length === 0 ? (
                <div className="flex flex-col items-start gap-3 rounded-xl border border-dashed bg-muted/20 p-5">
                    <div className="flex flex-col gap-1">
                        <p className="text-sm font-medium text-foreground">
                            No bundle parts yet for {item.label}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Add parts such as Main dish and Drink to turn this
                            item into a combo. Leave it with no parts and save
                            to sell it as a regular item.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="max-sm:min-h-11"
                        onClick={addPart}
                    >
                        <Plus data-icon="inline-start" />
                        Add first part
                    </Button>
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    {form.data.slots.map((slot, index) => {
                        const nameError = errors[`slots.${index}.name`];
                        const minError = errors[`slots.${index}.min`];
                        const maxError = errors[`slots.${index}.max`];
                        const optionsError = errors[`slots.${index}.options`];

                        return (
                            <FieldSet
                                key={index}
                                className="rounded-xl border bg-muted/15 p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <FieldLegend
                                            variant="label"
                                            className="mb-0"
                                        >
                                            Part {index + 1}
                                            {slot.name.trim() !== ''
                                                ? `: ${slot.name.trim()}`
                                                : ''}
                                        </FieldLegend>
                                        <Badge
                                            variant="outline"
                                            className="font-normal tabular-nums"
                                        >
                                            {slotRuleBadge(slot.min, slot.max)}
                                        </Badge>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="max-sm:min-h-11"
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

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_5.5rem_5.5rem]">
                                    <Field data-invalid={!!nameError}>
                                        <FieldLabel
                                            htmlFor={`combo-part-${index}`}
                                        >
                                            Part name
                                        </FieldLabel>
                                        <Input
                                            id={`combo-part-${index}`}
                                            maxLength={80}
                                            placeholder="e.g. Choose main, Choose drink"
                                            required
                                            value={slot.name}
                                            onChange={(event) =>
                                                setSlot(index, {
                                                    name: event.target.value,
                                                })
                                            }
                                            aria-invalid={!!nameError}
                                        />
                                        <FieldError>{nameError}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!minError}>
                                        <FieldLabel
                                            htmlFor={`combo-min-${index}`}
                                        >
                                            Min picks
                                        </FieldLabel>
                                        <Input
                                            id={`combo-min-${index}`}
                                            type="number"
                                            min={0}
                                            max={20}
                                            className="tabular-nums"
                                            value={slot.min}
                                            onChange={(event) =>
                                                setSlot(index, {
                                                    min: event.target.value,
                                                })
                                            }
                                            aria-invalid={!!minError}
                                        />
                                        <FieldError>{minError}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!maxError}>
                                        <FieldLabel
                                            htmlFor={`combo-max-${index}`}
                                        >
                                            Max picks
                                        </FieldLabel>
                                        <Input
                                            id={`combo-max-${index}`}
                                            type="number"
                                            min={1}
                                            max={20}
                                            className="tabular-nums"
                                            value={slot.max}
                                            onChange={(event) =>
                                                setSlot(index, {
                                                    max: event.target.value,
                                                })
                                            }
                                            aria-invalid={!!maxError}
                                        />
                                        <FieldError>{maxError}</FieldError>
                                    </Field>
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    Set Min picks to 0 to make this part
                                    optional for cashiers.
                                </p>

                                <div className="flex flex-col gap-3 border-t pt-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-xs font-medium text-muted-foreground">
                                            Allowed items in this part (
                                            {slot.options.length})
                                        </span>
                                    </div>
                                    <FieldError>{optionsError}</FieldError>
                                    {slot.options.map((option, optionIndex) => {
                                        const variantError =
                                            errors[
                                                `slots.${index}.options.${optionIndex}.variant_id`
                                            ];
                                        const upchargeError =
                                            errors[
                                                `slots.${index}.options.${optionIndex}.upcharge`
                                            ];

                                        return (
                                            <div
                                                key={optionIndex}
                                                className="grid grid-cols-1 items-start gap-2.5 sm:grid-cols-[minmax(0,1fr)_9.5rem_auto]"
                                            >
                                                <Field
                                                    data-invalid={
                                                        !!variantError
                                                    }
                                                >
                                                    <FieldLabel
                                                        htmlFor={`combo-opt-${index}-${optionIndex}`}
                                                    >
                                                        Option item
                                                    </FieldLabel>
                                                    <EnumSelect
                                                        id={`combo-opt-${index}-${optionIndex}`}
                                                        name={`slots_${index}_options_${optionIndex}`}
                                                        options={
                                                            allowedVariants
                                                        }
                                                        value={
                                                            option.variant_id
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setSlot(index, {
                                                                options:
                                                                    slot.options.map(
                                                                        (
                                                                            row,
                                                                            at,
                                                                        ) =>
                                                                            at ===
                                                                            optionIndex
                                                                                ? {
                                                                                      ...row,
                                                                                      variant_id:
                                                                                          value,
                                                                                  }
                                                                                : row,
                                                                    ),
                                                            })
                                                        }
                                                        placeholder="Pick a menu item"
                                                        aria-invalid={
                                                            !!variantError
                                                        }
                                                    />
                                                    <FieldError>
                                                        {variantError}
                                                    </FieldError>
                                                </Field>
                                                <Field
                                                    data-invalid={
                                                        !!upchargeError
                                                    }
                                                >
                                                    <FieldLabel
                                                        htmlFor={`combo-up-${index}-${optionIndex}`}
                                                    >
                                                        Extra upcharge
                                                    </FieldLabel>
                                                    <MoneyInput
                                                        id={`combo-up-${index}-${optionIndex}`}
                                                        currency={currency}
                                                        value={option.upcharge}
                                                        invalid={
                                                            !!upchargeError
                                                        }
                                                        onChange={(value) =>
                                                            setSlot(index, {
                                                                options:
                                                                    slot.options.map(
                                                                        (
                                                                            row,
                                                                            at,
                                                                        ) =>
                                                                            at ===
                                                                            optionIndex
                                                                                ? {
                                                                                      ...row,
                                                                                      upcharge:
                                                                                          value,
                                                                                  }
                                                                                : row,
                                                                    ),
                                                            })
                                                        }
                                                    />
                                                    <FieldError>
                                                        {upchargeError}
                                                    </FieldError>
                                                </Field>
                                                <div className="flex justify-end sm:pt-6">
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
                                                                        (
                                                                            _,
                                                                            at,
                                                                        ) =>
                                                                            at !==
                                                                            optionIndex,
                                                                    ),
                                                            })
                                                        }
                                                    >
                                                        <Trash2 data-icon="inline-start" />
                                                        Remove
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
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
                            </FieldSet>
                        );
                    })}
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="max-sm:min-h-11"
                        onClick={addPart}
                    >
                        <Plus data-icon="inline-start" />
                        Add part
                    </Button>
                    {form.isDirty && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="max-sm:min-h-11"
                            onClick={() => form.reset()}
                        >
                            <RotateCcw data-icon="inline-start" />
                            Discard changes
                        </Button>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {form.isDirty && (
                        <Badge variant="outline">Unsaved changes</Badge>
                    )}
                    <Button
                        type="submit"
                        className="max-sm:min-h-11"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        Save combo
                    </Button>
                </div>
            </div>
        </form>
    );
}
