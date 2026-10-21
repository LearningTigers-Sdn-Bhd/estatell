import { Link, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    CalendarDays,
    Equal,
    Rows2,
    TriangleAlert,
    WandSparkles,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
import { MoneyInput } from '@/components/pms/money-input';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { bulk } from '@/routes/rates/calendar';
import { save as bulkSave } from '@/routes/rates/calendar/bulk';
import { AutoFillDatesMenu } from './auto-fill-dates-menu';
import { BulkPriceTable } from './bulk-price-table';
import {
    applySetAll,
    buildPairGrids,
    bulkColumns,
    fitsColumns,
    hasPairValues,
    remapColumns,
    toBulkPayload,
} from './bulk-price-model';
import type {
    BulkView,
    PairGrid,
    SavedPrices,
    SetAllMode,
} from './bulk-price-model';
import { buildRows } from './pricing-state';
import {
    emptyRestrictionChoices,
    hasRestrictionChanges,
    restrictionsProblem,
    restrictionsToPayload,
} from './restriction-choices';
import { RestrictionsSection } from './restrictions-section';

type Plan = App.Data.Rates.RateBulkPlanData;

const MAX_DAYS = 731;

const SET_ALL_OPTIONS = [
    { value: 'price', label: 'Set price' },
    { value: 'percent', label: 'Change by %' },
    { value: 'amount', label: 'Change by amount' },
];

/**
 * The plan picker of the bulk edit. A Day use plan is edited on its own, so picking one drops the others.
 */
export function BulkPlanPicker({
    plans,
    value,
    onValueChange,
}: {
    plans: Plan[];
    value: string[];
    onValueChange: (value: string[]) => void;
}) {
    return (
        <Field className="w-full">
            <FieldLabel htmlFor="bulk-plans">Rate plans</FieldLabel>
            <MultiCombobox
                id="bulk-plans"
                name="plans"
                items={plans.map((plan) => ({
                    value: String(plan.id),
                    label: plan.name,
                    description:
                        plan.pricingBasis === 'day_use'
                            ? 'Day use, edited on its own'
                            : plan.derived
                              ? `Follows ${plan.followsName}, restrictions only`
                              : !plan.hasOwnPrices
                                ? 'Package, restrictions only'
                                : undefined,
                }))}
                value={value}
                onValueChange={onValueChange}
                placeholder="Pick the rate plans"
                emptyLabel="No matching rate plans."
            />
        </Field>
    );
}

/**
 * Bulk edit for plans with prices of their own and for derived and package plans (restrictions only): several plans
 * and room types over one date range, in one table. Nothing about the view or the weekend choice is stored.
 */
export function BulkEditForm({
    plans,
    planIds,
    onPlanIdsChange,
    currency,
    weekendNights,
    weekendNightsOptions,
    today,
    initialRange,
    saved,
    header,
    cancelHref,
}: {
    plans: Plan[];
    planIds: string[];
    onPlanIdsChange: (value: string[]) => void;
    currency: App.Data.Property.CurrencyData;
    weekendNights: string;
    weekendNightsOptions: App.Data.Property.WeekendNightsOptionData[];
    today: string;
    initialRange?: { from: string; to: string };
    saved: SavedPrices;
    /** The page title, at the top of the scroll region. */
    header: ReactNode;
    /** Where Cancel goes. */
    cancelHref: string;
}) {
    const selectedPlans = planIds
        .map((id) => plans.find((plan) => String(plan.id) === id))
        .filter((plan): plan is Plan => plan !== undefined);
    const roomTypes = [
        ...new Map(
            selectedPlans
                .flatMap((plan) => plan.roomTypes)
                .map((roomType) => [roomType.id, roomType]),
        ).values(),
    ];
    const form = useForm<{ from: string; to: string }>({
        from: initialRange?.from ?? '',
        to: initialRange?.to ?? '',
    });
    const [excluded, setExcluded] = useState<number[]>([]);
    const [store, setStore] = useState<PairGrid[]>([]);
    const grids = buildPairGrids(selectedPlans, excluded, store);
    const [choices, setChoices] = useState(emptyRestrictionChoices);
    const [view, setView] = useState<BulkView>('simple');
    const [weekendValue, setWeekendValue] = useState(weekendNights);
    const [notice, setNotice] = useState('');
    const [setAllMode, setSetAllMode] = useState<SetAllMode>('price');
    const [setAllValue, setSetAllValue] = useState('');
    const [setAllDirection, setSetAllDirection] = useState<'more' | 'less'>(
        'more',
    );
    const weekend =
        weekendNightsOptions.find((option) => option.value === weekendValue)
            ?.isoWeekdays ?? [];
    const columns = bulkColumns(view, weekend);
    const errors = form.errors as Record<string, string | undefined>;
    const dirty =
        form.isDirty || hasPairValues(grids) || hasRestrictionChanges(choices);
    const restrictionsError = restrictionsProblem(choices);
    const cannotSave =
        !form.data.from ||
        !form.data.to ||
        !(hasPairValues(grids) || hasRestrictionChanges(choices)) ||
        restrictionsError !== null;
    const guard = useUnsavedChanges(dirty);
    const gridErrors = [
        ...new Set(
            Object.entries(errors)
                .filter(([key]) => key.startsWith('plans'))
                .map(([, message]) => message),
        ),
    ];
    /** A change by a percent or an amount is typed as a size, and More or Less gives it its sign. */
    const signedSetAllValue = () => {
        const size = Math.abs(parseFloat(setAllValue));

        if (setAllMode === 'price' || Number.isNaN(size)) {
            return setAllValue;
        }

        return String(setAllDirection === 'less' ? -size : size);
    };
    const setAllBlocked = view === 'every-day' && setAllMode !== 'price';

    /** Keep the edited grids, also of a plan or room type that is picked off and on again. */
    const commit = (next: PairGrid[]) =>
        setStore((current) => [
            ...current.filter(
                (item) =>
                    !next.some(
                        (grid) =>
                            grid.ratePlanId === item.ratePlanId &&
                            grid.roomTypeId === item.roomTypeId,
                    ),
            ),
            ...next,
        ]);

    const changeRoomTypes = (next: string[]) =>
        setExcluded(
            roomTypes
                .map((roomType) => roomType.id)
                .filter((id) => !next.includes(String(id))),
        );

    const changeView = (next: BulkView) => {
        if (!fitsColumns(grids, bulkColumns(next, weekend))) {
            setNotice(
                'The prices differ by day, so this view cannot show them. Stay on a view with more columns, or clear the differences first.',
            );

            return;
        }

        setNotice('');
        setView(next);
    };

    const changeWeekend = (value: string) => {
        const next =
            weekendNightsOptions.find((option) => option.value === value)
                ?.isoWeekdays ?? weekend;

        if (view === 'simple') {
            commit(
                remapColumns(
                    grids,
                    bulkColumns('simple', weekend),
                    bulkColumns('simple', next),
                ),
            );
        }

        setWeekendValue(value);
    };

    const changeRange = (from: string, to: string) => {
        form.setData({ from, to });

        if (from && to) {
            // Reload only the saved prices of the range, so the placeholders and Set all follow it.
            guard.visitWithout(
                bulk.url(undefined, {
                    query: {
                        ...(planIds[0] ? { plan: planIds[0] } : {}),
                        from,
                        to,
                    },
                }),
                {
                    only: ['saved'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }
    };

    const toggleByAdults = (grid: PairGrid, byAdults: boolean) => {
        const roomType = roomTypes.find((item) => item.id === grid.roomTypeId);
        const plan = plans.find((item) => item.id === grid.ratePlanId);

        if (!roomType || !plan) {
            return;
        }

        commit(
            grids.map((item) =>
                item.ratePlanId === grid.ratePlanId &&
                item.roomTypeId === grid.roomTypeId
                    ? {
                          ...item,
                          byAdults,
                          rows: buildRows(
                              plan.pricingBasis,
                              { ...roomType, pricedUntil: null, ending: false },
                              byAdults,
                              item.rows,
                          ),
                      }
                    : item,
            ),
        );
    };

    const save = (onSaved?: () => void) => {
        form.transform((data) => ({
            ...data,
            plans: toBulkPayload(grids),
            restrictions: restrictionsToPayload(choices),
        }));
        form.put(bulkSave.url(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.reset();
                form.setDefaults();
                setStore([]);
                setChoices(emptyRestrictionChoices());
                onSaved?.();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <div
                scroll-region=""
                className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-4 [&>*]:shrink-0"
            >
                {header}
                <div className="grid gap-4 sm:grid-cols-2">
                    <BulkPlanPicker
                        plans={plans}
                        value={planIds}
                        onValueChange={onPlanIdsChange}
                    />
                    <Field className="w-full">
                        <FieldLabel htmlFor="bulk-room-types">
                            Room types
                        </FieldLabel>
                        <MultiCombobox
                            id="bulk-room-types"
                            name="room_types"
                            items={roomTypes.map((roomType) => ({
                                value: String(roomType.id),
                                label: roomType.name,
                            }))}
                            value={roomTypes
                                .filter(
                                    (roomType) =>
                                        !excluded.includes(roomType.id),
                                )
                                .map((roomType) => String(roomType.id))}
                            onValueChange={changeRoomTypes}
                            placeholder="Pick the room types"
                            emptyLabel="No matching room types."
                        />
                    </Field>
                </div>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Field
                        data-invalid={!!errors.from || !!errors.to}
                        className="w-full sm:max-w-xl sm:flex-1"
                    >
                        <FieldLabel htmlFor="bulk-dates">Dates</FieldLabel>
                        <div className="flex items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <DateRangePicker
                                    id="bulk-dates"
                                    from={form.data.from}
                                    to={form.data.to}
                                    onChange={changeRange}
                                    min={today}
                                    maxDays={MAX_DAYS}
                                    months={2}
                                    unit="night"
                                    placeholder="Pick the dates to price"
                                    aria-invalid={!!errors.from || !!errors.to}
                                />
                            </div>
                            <AutoFillDatesMenu
                                today={today}
                                onPick={changeRange}
                            />
                        </div>
                        <FieldDescription>
                            Every date in the range gets the price of its
                            weekday.
                        </FieldDescription>
                        <FieldError>{errors.from ?? errors.to}</FieldError>
                    </Field>
                    <div className="flex flex-wrap items-start gap-3">
                        <Field className="w-auto">
                            <FieldLabel>View</FieldLabel>
                            <ToggleGroup
                                variant="outline"
                                spacing={0}
                                value={[view]}
                                onValueChange={(value) =>
                                    value[0] && changeView(value[0] as BulkView)
                                }
                                aria-label="Pricing view"
                            >
                                <ToggleGroupItem value="every-day">
                                    <Equal data-icon="inline-start" />
                                    Every day
                                </ToggleGroupItem>
                                <ToggleGroupItem value="simple">
                                    <Rows2 data-icon="inline-start" />
                                    Weekday / weekend
                                </ToggleGroupItem>
                                <ToggleGroupItem value="by-day">
                                    <CalendarDays data-icon="inline-start" />
                                    Each day
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </Field>
                        {view === 'simple' && (
                            <Field className="w-52">
                                <FieldLabel htmlFor="bulk-weekend">
                                    Weekend nights
                                </FieldLabel>
                                <EnumSelect
                                    id="bulk-weekend"
                                    options={weekendNightsOptions}
                                    value={weekendValue}
                                    onValueChange={changeWeekend}
                                />
                            </Field>
                        )}
                    </div>
                </div>
                {notice && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                )}
                <div className="flex flex-col gap-3 rounded-lg border bg-muted/30 p-3">
                    <div className="flex flex-wrap items-end gap-3">
                        <Field className="w-48">
                            <FieldLabel htmlFor="bulk-set-all-mode">
                                Set all
                            </FieldLabel>
                            <EnumSelect
                                id="bulk-set-all-mode"
                                options={SET_ALL_OPTIONS}
                                value={setAllMode}
                                onValueChange={(mode) =>
                                    setSetAllMode(mode as SetAllMode)
                                }
                            />
                        </Field>
                        {setAllMode !== 'price' && (
                            <Field className="w-auto">
                                <FieldLabel className="sr-only">
                                    Direction
                                </FieldLabel>
                                <ToggleGroup
                                    variant="outline"
                                    spacing={0}
                                    value={[setAllDirection]}
                                    onValueChange={(value) =>
                                        value[0] &&
                                        setSetAllDirection(
                                            value[0] as 'more' | 'less',
                                        )
                                    }
                                    aria-label="More or less than the saved price"
                                >
                                    <ToggleGroupItem value="more">
                                        <ArrowUp data-icon="inline-start" />
                                        More
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="less">
                                        <ArrowDown data-icon="inline-start" />
                                        Less
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </Field>
                        )}
                        <Field className="w-44">
                            <FieldLabel
                                htmlFor="bulk-set-all-value"
                                className="sr-only"
                            >
                                Value to set on all rows
                            </FieldLabel>
                            {setAllMode === 'price' ? (
                                <MoneyInput
                                    id="bulk-set-all-value"
                                    value={setAllValue}
                                    currency={currency}
                                    onChange={setSetAllValue}
                                />
                            ) : (
                                <InputGroup>
                                    <InputGroupInput
                                        id="bulk-set-all-value"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="decimal"
                                        placeholder="e.g. 10"
                                        value={setAllValue}
                                        onChange={(event) =>
                                            setSetAllValue(event.target.value)
                                        }
                                    />
                                    <InputGroupAddon align="inline-end">
                                        {setAllMode === 'percent'
                                            ? '%'
                                            : currency.code}
                                    </InputGroupAddon>
                                </InputGroup>
                            )}
                        </Field>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                setAllBlocked || setAllValue.trim() === ''
                            }
                            focusableWhenDisabled
                            onClick={() =>
                                commit(
                                    applySetAll(
                                        grids,
                                        columns,
                                        setAllMode,
                                        signedSetAllValue(),
                                        saved,
                                    ),
                                )
                            }
                        >
                            <WandSparkles data-icon="inline-start" />
                            Apply to all rows
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {setAllBlocked
                            ? 'A change by % or amount needs the Weekday / weekend or Each day view.'
                            : 'Fills the main price of every row. A change starts from the saved price.'}
                    </p>
                </div>
                {grids.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Pick a rate plan and at least one room type.
                    </p>
                ) : (
                    <>
                        <BulkPriceTable
                            grids={grids}
                            plans={selectedPlans}
                            roomTypes={roomTypes}
                            columns={columns}
                            saved={saved}
                            currency={currency}
                            onChange={(next) => commit([next])}
                            onToggleByAdults={toggleByAdults}
                        />
                        <p className="text-sm text-muted-foreground">
                            Leave a price blank to keep what is saved for that
                            day. Saving a price replaces the prices by adults of
                            those dates, so fill them too to keep them.
                        </p>
                    </>
                )}
                <RestrictionsSection
                    choices={choices}
                    onChange={setChoices}
                    errors={errors}
                />
                {gridErrors.map((message) => (
                    <FieldError key={message}>{message}</FieldError>
                ))}
            </div>
            <FormActions fixed>
                <Link
                    href={cancelHref}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    Cancel
                </Link>
                <Button
                    type="submit"
                    disabled={form.processing || cannotSave}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save changes
                </Button>
            </FormActions>
            <UnsavedChangesDialog
                guard={guard}
                name="the bulk edit"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
