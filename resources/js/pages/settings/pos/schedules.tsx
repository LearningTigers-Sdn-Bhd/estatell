import { Link, router } from '@inertiajs/react';
import { CalendarClock, Layers, Pencil, Plus, Store } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DiscardChangesDialog } from '@/components/pms/discard-changes-dialog';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import type { MultiComboboxOption } from '@/components/pms/multi-combobox';
import {
    PosComboForm,
    PosScheduleForm,
    SCHEDULE_DAYS,
    scheduleDaysLabel,
} from '@/components/pms/pos/pos-schedule-forms';
import type {
    ComboRow,
    ComboVariantOption,
    ScheduleRow,
} from '@/components/pms/pos/pos-schedule-forms';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { cn } from '@/lib/utils';
import { index as posOutletsIndex } from '@/routes/settings/pos-outlets';
import { index as schedulesIndex } from '@/routes/settings/pos/schedules';

type Props = {
    currency: App.Data.Property.CurrencyData | null;
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    schedules: ScheduleRow[];
    categories: MultiComboboxOption[];
    items: MultiComboboxOption[];
    mealPeriods: EnumOption[];
    combos: ComboRow[];
    variants: ComboVariantOption[];
};

const DAY_INITIALS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

function formatScope(
    categoryIds: number[],
    itemIds: number[],
    categoryNames: Map<number, string>,
    itemNames: Map<number, string>,
): string {
    const parts: string[] = [];

    if (categoryIds.length > 0) {
        const names = categoryIds
            .map((id) => categoryNames.get(id))
            .filter((name): name is string => Boolean(name));
        parts.push(
            names.length > 0 && names.length <= 2
                ? names.join(', ')
                : `${categoryIds.length} ${categoryIds.length === 1 ? 'category' : 'categories'}`,
        );
    }

    if (itemIds.length > 0) {
        const names = itemIds
            .map((id) => itemNames.get(id))
            .filter((name): name is string => Boolean(name));
        parts.push(
            names.length > 0 && names.length <= 2
                ? names.join(', ')
                : `${itemIds.length} ${itemIds.length === 1 ? 'item' : 'items'}`,
        );
    }

    return parts.length > 0 ? parts.join(' · ') : 'No targets selected';
}

export default function PosSchedules({
    currency,
    outlets,
    outlet,
    schedules,
    categories,
    items,
    mealPeriods,
    combos,
    variants,
}: Props) {
    const [editing, setEditing] = useState<{ row: ScheduleRow | null } | null>(
        null,
    );
    const [comboItem, setComboItem] = useState(
        combos[0] ? String(combos[0].id) : '',
    );
    const [comboDirty, setComboDirty] = useState(false);
    const [pendingComboItem, setPendingComboItem] = useState<string | null>(
        null,
    );

    const selected = items.find((item) => item.value === comboItem);

    const categoryNames = useMemo(
        () => new Map(categories.map((cat) => [Number(cat.value), cat.label])),
        [categories],
    );
    const itemNames = useMemo(
        () => new Map(items.map((item) => [Number(item.value), item.label])),
        [items],
    );
    const mealPeriodNames = useMemo(
        () =>
            new Map(
                mealPeriods.map((period) => [
                    Number(period.value),
                    period.label,
                ]),
            ),
        [mealPeriods],
    );

    const comboIds = useMemo(
        () => new Set(combos.map((combo) => String(combo.id))),
        [combos],
    );

    const groupedComboItemOptions = useMemo<EnumOption[]>(() => {
        const unconfigured = items
            .filter((entry) => !comboIds.has(entry.value))
            .map((entry) => ({
                ...entry,
                group:
                    combos.length > 0
                        ? 'Turn a menu item into a combo'
                        : 'Menu items',
            }));
        const configured = items
            .filter((entry) => comboIds.has(entry.value))
            .map((entry) => ({
                ...entry,
                group: 'Already configured combos',
            }));

        return [...unconfigured, ...configured];
    }, [items, comboIds, combos.length]);

    const activeScheduleCount = schedules.filter(
        (schedule) => schedule.active,
    ).length;

    const selectComboItem = (nextId: string) => {
        if (nextId === comboItem) {
            return;
        }

        if (comboDirty) {
            setPendingComboItem(nextId);

            return;
        }

        setComboItem(nextId);
    };

    const headerActions = (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {outlets.length > 1 && (
                <EnumSelect
                    name="outlet"
                    options={outlets}
                    value={String(outlet?.id ?? '')}
                    onValueChange={(value) =>
                        router.get(
                            schedulesIndex.url(undefined, {
                                query: { outlet: value },
                            }),
                        )
                    }
                    icon={<Store aria-hidden />}
                    aria-label="Outlet for menu schedules"
                    className="w-48"
                />
            )}
            <Button
                type="button"
                className="max-sm:min-h-11"
                disabled={outlet === null}
                onClick={() => setEditing({ row: null })}
            >
                <Plus data-icon="inline-start" />
                Add schedule
            </Button>
        </div>
    );

    return (
        <PosSettingsShell activeTab="schedules" actions={headerActions}>
            <div className="flex flex-col gap-6">
                <section className="overflow-hidden rounded-xl border bg-card">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3.5">
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-heading text-base font-medium">
                                    Menu schedules
                                </h2>
                                {outlet && (
                                    <Badge variant="secondary">
                                        {outlet.name}
                                    </Badge>
                                )}
                                {schedules.length > 0 && (
                                    <Badge variant="outline">
                                        <span className="tabular-nums">
                                            {activeScheduleCount} active of{' '}
                                            {schedules.length}
                                        </span>
                                    </Badge>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {outlet
                                    ? `Time windows for ${outlet.name}. Items not named in any schedule stay on sale all day.`
                                    : 'Enable built-in POS on an outlet to configure time-of-day menu schedules.'}
                            </p>
                        </div>
                    </header>

                    {outlet === null ? (
                        <div className="p-6">
                            <EmptyState
                                icon={Store}
                                title="No POS outlet available"
                                description="Enable built-in POS on at least one outlet under Setup to create menu schedules."
                                action={
                                    <Link
                                        href={posOutletsIndex()}
                                        className={buttonVariants({
                                            variant: 'outline',
                                        })}
                                    >
                                        Go to Outlets
                                    </Link>
                                }
                            />
                        </div>
                    ) : schedules.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={CalendarClock}
                                title="No menu schedules yet"
                                description="Every item is currently on sale all day. Create a schedule to limit categories or items to breakfast, lunch, or happy hour."
                                action={
                                    <Button
                                        type="button"
                                        className="max-sm:min-h-11"
                                        onClick={() =>
                                            setEditing({ row: null })
                                        }
                                    >
                                        <Plus data-icon="inline-start" />
                                        Add your first schedule
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <div className="divide-y">
                            {schedules.map((schedule) => {
                                const mealLabel = schedule.mealPeriodId
                                    ? mealPeriodNames.get(schedule.mealPeriodId)
                                    : null;
                                const dateRange =
                                    schedule.effectiveFrom ||
                                    schedule.effectiveUntil
                                        ? `${schedule.effectiveFrom ?? 'Start'} to ${schedule.effectiveUntil ?? 'Ongoing'}`
                                        : null;
                                const isOvernight =
                                    schedule.endTime < schedule.startTime;

                                return (
                                    <div
                                        key={schedule.id}
                                        className="flex flex-col gap-3 px-4 py-3.5 md:flex-row md:items-center md:justify-between"
                                    >
                                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium text-foreground">
                                                    {schedule.name}
                                                </span>
                                                <StatusBadge
                                                    status={{
                                                        value: schedule.active
                                                            ? 'active'
                                                            : 'off',
                                                        label: schedule.active
                                                            ? 'Active'
                                                            : 'Off',
                                                        tone: schedule.active
                                                            ? 'success'
                                                            : 'neutral',
                                                    }}
                                                />
                                                {mealLabel && (
                                                    <Badge variant="outline">
                                                        {mealLabel}
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {formatScope(
                                                    schedule.categoryIds,
                                                    schedule.itemIds,
                                                    categoryNames,
                                                    itemNames,
                                                )}
                                                {dateRange
                                                    ? ` · Dates: ${dateRange}`
                                                    : ''}
                                            </p>
                                        </div>

                                        <div className="flex flex-wrap items-center justify-between gap-3 md:justify-end">
                                            <div className="flex flex-wrap items-center gap-3">
                                                <div className="flex items-center gap-1">
                                                    <span className="sr-only">
                                                        {scheduleDaysLabel(
                                                            schedule.daysOfWeek,
                                                        )}
                                                    </span>
                                                    {DAY_INITIALS.map(
                                                        (letter, index) => {
                                                            const on =
                                                                (schedule.daysOfWeek &
                                                                    (1 <<
                                                                        index)) !==
                                                                0;

                                                            return (
                                                                <span
                                                                    key={
                                                                        SCHEDULE_DAYS[
                                                                            index
                                                                        ]
                                                                    }
                                                                    aria-hidden
                                                                    title={
                                                                        SCHEDULE_DAYS[
                                                                            index
                                                                        ]
                                                                    }
                                                                    className={cn(
                                                                        'inline-flex size-6 items-center justify-center rounded-md text-xs font-medium',
                                                                        on
                                                                            ? 'bg-primary text-primary-foreground'
                                                                            : 'bg-muted text-muted-foreground',
                                                                    )}
                                                                >
                                                                    {letter}
                                                                </span>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                                <span className="text-sm font-medium text-foreground tabular-nums">
                                                    {schedule.startTime} to{' '}
                                                    {schedule.endTime}
                                                    {isOvernight && (
                                                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                                                            (next day)
                                                        </span>
                                                    )}
                                                </span>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="ml-auto max-sm:min-h-11 md:ml-0"
                                                aria-label={`Edit schedule ${schedule.name}`}
                                                onClick={() =>
                                                    setEditing({
                                                        row: schedule,
                                                    })
                                                }
                                            >
                                                <Pencil data-icon="inline-start" />
                                                Edit
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-xl border bg-card">
                    <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3.5">
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-heading text-base font-medium">
                                    Combos
                                </h2>
                                <Badge variant="secondary">All outlets</Badge>
                                <Badge variant="outline">
                                    <span className="tabular-nums">
                                        {combos.length} configured
                                    </span>
                                </Badge>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Turn any menu item into a set meal by adding
                                bundle parts such as a main and a drink. The
                                item&apos;s own menu price is the base combo
                                price, and each option can add an upcharge.
                            </p>
                        </div>
                    </header>

                    <div className="grid grid-cols-1 divide-y lg:grid-cols-[18rem_minmax(0,1fr)] lg:divide-x lg:divide-y-0">
                        <div className="flex flex-col gap-4 p-4">
                            {combos.length > 0 && (
                                <div className="flex flex-col gap-1.5">
                                    <span className="text-xs font-medium text-muted-foreground">
                                        Configured combos
                                    </span>
                                    <div className="flex flex-col gap-1">
                                        {combos.map((combo) => {
                                            const active =
                                                comboItem === String(combo.id);
                                            const optionCount =
                                                combo.slots.reduce(
                                                    (sum, slot) =>
                                                        sum +
                                                        slot.options.length,
                                                    0,
                                                );

                                            return (
                                                <button
                                                    key={combo.id}
                                                    type="button"
                                                    aria-pressed={active}
                                                    onClick={() =>
                                                        selectComboItem(
                                                            String(combo.id),
                                                        )
                                                    }
                                                    className={cn(
                                                        'flex w-full flex-col gap-0.5 rounded-lg px-3 py-2 text-left transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none',
                                                        active
                                                            ? 'bg-muted font-medium text-foreground ring-1 ring-border'
                                                            : 'text-foreground/85 hover:bg-muted/60',
                                                    )}
                                                >
                                                    <span className="truncate text-sm">
                                                        {combo.name}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        {combo.slots.length}{' '}
                                                        {combo.slots.length ===
                                                        1
                                                            ? 'part'
                                                            : 'parts'}{' '}
                                                        · {optionCount}{' '}
                                                        {optionCount === 1
                                                            ? 'option'
                                                            : 'options'}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            <Field>
                                <FieldLabel htmlFor="combo-item-picker">
                                    {combos.length > 0
                                        ? 'Configure another menu item'
                                        : 'Pick a menu item to make a combo'}
                                </FieldLabel>
                                <FieldDescription>
                                    {items.length === 0
                                        ? 'Create the combo item on the Menu page first (for example "Set Lunch").'
                                        : 'Choose the menu item that represents this bundle.'}
                                </FieldDescription>
                                <EnumSelect
                                    id="combo-item-picker"
                                    name="combo_item"
                                    options={groupedComboItemOptions}
                                    value={comboItem}
                                    onValueChange={selectComboItem}
                                    placeholder="Pick a menu item"
                                    disabled={items.length === 0}
                                />
                            </Field>
                        </div>

                        <div className="min-w-0 p-4">
                            {selected !== undefined ? (
                                <div className="flex flex-col gap-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-3">
                                        <div className="flex flex-col gap-0.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="font-heading text-base font-medium">
                                                    {selected.label}
                                                </h3>
                                                {comboIds.has(
                                                    selected.value,
                                                ) && (
                                                    <Badge variant="outline">
                                                        Combo active
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                Define the parts and allowed
                                                items cashiers choose when
                                                selling this combo.
                                            </p>
                                        </div>
                                    </div>
                                    <PosComboForm
                                        key={selected.value}
                                        item={selected}
                                        combo={combos.find(
                                            (combo) =>
                                                String(combo.id) ===
                                                selected.value,
                                        )}
                                        currency={currency}
                                        variants={variants}
                                        onDirtyChange={setComboDirty}
                                    />
                                </div>
                            ) : (
                                <EmptyState
                                    icon={Layers}
                                    title="Select a menu item to build a combo"
                                    description={
                                        items.length === 0
                                            ? 'Add menu items on the Menu page first before building combos.'
                                            : combos.length === 0
                                              ? 'Pick any menu item on the left to add bundle parts such as a main and a drink.'
                                              : 'Pick a configured combo or choose another menu item on the left to edit its bundle parts.'
                                    }
                                />
                            )}
                        </div>
                    </div>
                </section>
            </div>

            {editing !== null && outlet !== null && (
                <PosScheduleForm
                    key={editing.row?.id ?? 'new'}
                    outletId={outlet.id}
                    row={editing.row}
                    categories={categories}
                    items={items}
                    mealPeriods={mealPeriods}
                    onClose={() => setEditing(null)}
                />
            )}

            <DiscardChangesDialog
                open={pendingComboItem !== null}
                onOpenChange={(open) => !open && setPendingComboItem(null)}
                title="Discard unsaved combo changes?"
                onDiscard={() => {
                    if (pendingComboItem !== null) {
                        setComboItem(pendingComboItem);
                        setComboDirty(false);
                        setPendingComboItem(null);
                    }
                }}
            />
        </PosSettingsShell>
    );
}

PosSchedules.layout = {
    breadcrumbs: posSettingsBreadcrumbs('schedules'),
};
