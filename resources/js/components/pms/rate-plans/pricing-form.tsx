import { useForm } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronDown,
    ChevronRight,
    Rows2,
    TriangleAlert,
    WandSparkles,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/rate-plans/pricing';
import {
    emptyRestrictionChoices,
    hasRestrictionChanges,
    restrictionsProblem,
    restrictionsToPayload,
} from './restriction-choices';
import { RestrictionsSection } from './restrictions-section';
import {
    autoFill,
    autoFillDayUse,
    autoFillPerGuest,
    buildRows,
    fitsSimple,
    hasSeed,
    hasValues,
    perPersonShare,
    remapWeekend,
    setSimpleValue,
    simpleValue,
    toPayload,
    WEEKDAYS,
} from './pricing-state';
import type {
    AutoFillOptions,
    DayUseAutoFillOptions,
    GuestAutoFillOptions,
    PricingRow,
    RoomTypeGrid,
} from './pricing-state';

type RatePlan = App.Data.Rates.RatePlanData;
type RoomType = App.Data.Rates.RatePricingRoomTypeData;
type WeekendOption = App.Data.Property.WeekendNightsOptionData;
type Mode = 'simple' | 'by-day';

const MAX_DAYS = 731;

/** The last date of a 12-month range that starts on `start` (`Y-m-d`), for example 2026-10-01 to 2027-09-30. */
function twelveMonthsEnd(start: string): string {
    const [year, month, day] = start.split('-').map(Number);
    const end = new Date(year, month - 1 + 12, day - 1);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${end.getFullYear()}-${pad(end.getMonth() + 1)}-${pad(end.getDate())}`;
}

/**
 * Lets staff set the price of a plan for a date range: one price per weekday and weekend in Simple,
 * or one per day of the week in By day. Nothing about the mode or the weekend choice is stored.
 */
export function PricingForm({
    ratePlan,
    roomTypes,
    currency,
    weekendNights,
    weekendNightsOptions,
    today,
    saveUrl,
    initialRange,
    restrictions = false,
    prices = true,
    submitLabel = 'Save prices',
}: {
    ratePlan: RatePlan;
    roomTypes: RoomType[];
    currency: App.Enums.Currency;
    weekendNights: string;
    weekendNightsOptions: WeekendOption[];
    today: string;
    /** Where the form saves. The plan editor's Pricing tab route when left out. */
    saveUrl?: string;
    /** The dates to start with. Empty when left out. */
    initialRange?: { from: string; to: string };
    /** Show the restrictions a bulk edit can change, and send them with the prices. */
    restrictions?: boolean;
    /** Show the price table. Off for a plan with no prices of its own, so only restrictions can change. */
    prices?: boolean;
    submitLabel?: string;
}) {
    const basis = ratePlan.pricingBasis;
    const hoursRange =
        basis === 'day_use' &&
        ratePlan.dayUseMinHours !== null &&
        ratePlan.dayUseMaxHours !== null
            ? { min: ratePlan.dayUseMinHours, max: ratePlan.dayUseMaxHours }
            : null;
    const emptyGrids = () =>
        roomTypes.map((roomType): RoomTypeGrid => ({
            roomTypeId: roomType.id,
            byAdults: false,
            rows: buildRows(basis, roomType, false, [], hoursRange),
        }));
    const form = useForm<{ from: string; to: string }>({
        from: initialRange?.from ?? '',
        to: initialRange?.to ?? '',
    });
    const [choices, setChoices] = useState(emptyRestrictionChoices);
    const [grids, setGrids] = useState<RoomTypeGrid[]>(emptyGrids);
    const [mode, setMode] = useState<Mode>('simple');
    const [weekendValue, setWeekendValue] = useState(weekendNights);
    const [notice, setNotice] = useState('');
    const [openRoomTypeId, setOpenRoomTypeId] = useState(roomTypes[0]?.id);
    const [collapsed, setCollapsed] = useState<number[]>([]);
    const dirty =
        form.isDirty || hasValues(grids) || hasRestrictionChanges(choices);
    const restrictionsError = restrictions
        ? restrictionsProblem(choices)
        : null;
    const cannotSave =
        restrictions &&
        (!form.data.from ||
            !form.data.to ||
            !(hasValues(grids) || hasRestrictionChanges(choices)) ||
            restrictionsError !== null);
    const guard = useUnsavedChanges(dirty);
    const errors = form.errors as Record<string, string | undefined>;
    const weekend =
        weekendNightsOptions.find((option) => option.value === weekendValue)
            ?.isoWeekdays ?? [];
    const gridErrors = [
        ...new Set(
            Object.entries(errors)
                .filter(([key]) => key.startsWith('room_types'))
                .map(([, message]) => message),
        ),
    ];
    const accordion =
        basis === 'per_guest' && mode === 'by-day' && roomTypes.length > 1;
    const isOpen = (id: number) =>
        accordion ? id === openRoomTypeId : !collapsed.includes(id);
    const columns =
        mode === 'simple'
            ? [
                  { key: 'weekday' as const, label: 'Weekdays' },
                  { key: 'weekend' as const, label: 'Weekend' },
              ]
            : WEEKDAYS.map((day, index) => ({ index, label: day.label }));
    const cellValue = (row: PricingRow, column: (typeof columns)[number]) =>
        'key' in column
            ? simpleValue(row, column.key, weekend)
            : row.values[column.index];

    const changeRows = (
        roomTypeId: number,
        change: (rows: PricingRow[]) => PricingRow[],
    ) => {
        setGrids((current) =>
            current.map((grid) =>
                grid.roomTypeId === roomTypeId
                    ? { ...grid, rows: change(grid.rows) }
                    : grid,
            ),
        );
    };

    const toggleOpen = (id: number) => {
        if (accordion) {
            setOpenRoomTypeId(id);

            return;
        }

        setCollapsed((current) =>
            current.includes(id)
                ? current.filter((item) => item !== id)
                : [...current, id],
        );
    };

    const changeCell = (
        roomTypeId: number,
        rowId: string,
        column: { key: 'weekday' | 'weekend' } | { index: number },
        value: string,
    ) => {
        changeRows(roomTypeId, (rows) =>
            rows.map((item) => {
                if (item.id !== rowId) {
                    return item;
                }

                if ('key' in column) {
                    return setSimpleValue(item, column.key, weekend, value);
                }

                const values = [...item.values];
                values[column.index] = value;

                return { ...item, values };
            }),
        );
    };

    const changeMode = (next: Mode) => {
        if (next === 'simple' && !fitsSimple(grids, weekend)) {
            setNotice(
                'The prices differ by day, so Simple cannot show them. Stay on By day, or clear the differences first.',
            );

            return;
        }

        setNotice('');
        setMode(next);
    };

    const changeWeekend = (value: string) => {
        const next =
            weekendNightsOptions.find((option) => option.value === value)
                ?.isoWeekdays ?? weekend;

        if (mode === 'simple') {
            setGrids((current) => remapWeekend(current, weekend, next));
        }

        setWeekendValue(value);
    };

    const toggleByAdults = (roomType: RoomType, byAdults: boolean) => {
        setGrids((current) =>
            current.map((grid) =>
                grid.roomTypeId === roomType.id
                    ? {
                          ...grid,
                          byAdults,
                          rows: buildRows(
                              basis,
                              roomType,
                              byAdults,
                              grid.rows,
                              hoursRange,
                          ),
                      }
                    : grid,
            ),
        );
    };

    const save = (onSaved?: () => void) => {
        form.transform((data) => ({
            ...data,
            room_types: toPayload(grids),
            ...(restrictions
                ? { restrictions: restrictionsToPayload(choices) }
                : {}),
        }));
        form.put(saveUrl ?? update.url({ ratePlan: ratePlan.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.reset();
                form.setDefaults();
                setGrids(emptyGrids());
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
        <form onSubmit={submit} className="flex flex-col gap-5">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <Field
                    data-invalid={!!errors.from || !!errors.to}
                    className="w-full sm:max-w-xl sm:flex-1"
                >
                    <FieldLabel htmlFor="pricing-dates">Dates</FieldLabel>
                    <div className="flex items-center gap-2">
                        <div className="min-w-0 flex-1">
                            <DateRangePicker
                                id="pricing-dates"
                                from={form.data.from}
                                to={form.data.to}
                                onChange={(from, to) =>
                                    form.setData({ from, to })
                                }
                                min={today}
                                maxDays={MAX_DAYS}
                                months={2}
                                unit="night"
                                placeholder="Pick the dates to price"
                                aria-invalid={!!errors.from || !!errors.to}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData({
                                    from: today,
                                    to: twelveMonthsEnd(today),
                                })
                            }
                        >
                            <WandSparkles data-icon="inline-start" />
                            12 months from now
                        </Button>
                    </div>
                    <FieldDescription>
                        {prices
                            ? 'Every date in the range gets the price of its weekday.'
                            : 'The restrictions apply to every date in the range.'}
                    </FieldDescription>
                    <FieldError>{errors.from ?? errors.to}</FieldError>
                </Field>
                {prices && (
                    <div className="flex flex-wrap items-start gap-3">
                        <Field className="w-auto">
                            <FieldLabel>View</FieldLabel>
                            <ToggleGroup
                                variant="outline"
                                spacing={0}
                                value={[mode]}
                                onValueChange={(value) =>
                                    value[0] && changeMode(value[0] as Mode)
                                }
                                aria-label="Pricing view"
                            >
                                <ToggleGroupItem value="simple">
                                    <Rows2 data-icon="inline-start" />
                                    Simple
                                </ToggleGroupItem>
                                <ToggleGroupItem value="by-day">
                                    <CalendarDays data-icon="inline-start" />
                                    By day
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </Field>
                        {mode === 'simple' && (
                            <Field className="w-52">
                                <FieldLabel htmlFor="pricing-weekend">
                                    Weekend nights
                                </FieldLabel>
                                <EnumSelect
                                    id="pricing-weekend"
                                    options={weekendNightsOptions}
                                    value={weekendValue}
                                    onValueChange={changeWeekend}
                                />
                            </Field>
                        )}
                        {notice && <FieldError>{notice}</FieldError>}
                    </div>
                )}
            </div>
            {prices && (
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Price</TableHead>
                                {columns.map((column) => (
                                    <TableHead key={column.label}>
                                        {column.label}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roomTypes.map((roomType) => {
                                const grid = grids.find(
                                    (item) => item.roomTypeId === roomType.id,
                                )!;
                                const open = isOpen(roomType.id);

                                return (
                                    <Fragment key={roomType.id}>
                                        <TableRow className="bg-muted/50 hover:bg-muted/50">
                                            <TableCell
                                                colSpan={columns.length + 1}
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <div className="flex flex-wrap items-center gap-3">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-expanded={open}
                                                            onClick={() =>
                                                                toggleOpen(
                                                                    roomType.id,
                                                                )
                                                            }
                                                        >
                                                            {open ? (
                                                                <ChevronDown data-icon="inline-start" />
                                                            ) : (
                                                                <ChevronRight data-icon="inline-start" />
                                                            )}
                                                            {roomType.name}
                                                        </Button>
                                                        {roomType.pricedUntil ? (
                                                            <span className="text-sm text-muted-foreground">
                                                                Priced until{' '}
                                                                <DateText
                                                                    date={
                                                                        roomType.pricedUntil
                                                                    }
                                                                />
                                                            </span>
                                                        ) : (
                                                            <Badge variant="outline">
                                                                No prices yet
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-4">
                                                        {grid.rows.some(
                                                            (row) =>
                                                                row.kind ===
                                                                    'occupancy' ||
                                                                row.kind ===
                                                                    'hours',
                                                        ) && (
                                                            <AutoFill
                                                                roomType={
                                                                    roomType
                                                                }
                                                                basis={basis}
                                                                rows={grid.rows}
                                                                currency={
                                                                    currency
                                                                }
                                                                onApply={(
                                                                    fill,
                                                                ) =>
                                                                    changeRows(
                                                                        roomType.id,
                                                                        fill,
                                                                    )
                                                                }
                                                            />
                                                        )}
                                                        {basis ===
                                                            'per_room' && (
                                                            <Field
                                                                orientation="horizontal"
                                                                className="w-auto"
                                                            >
                                                                <Switch
                                                                    id={`by-adults-${roomType.id}`}
                                                                    checked={
                                                                        grid.byAdults
                                                                    }
                                                                    onCheckedChange={(
                                                                        checked,
                                                                    ) =>
                                                                        toggleByAdults(
                                                                            roomType,
                                                                            checked,
                                                                        )
                                                                    }
                                                                />
                                                                <FieldContent className="flex-none">
                                                                    <FieldLabel
                                                                        htmlFor={`by-adults-${roomType.id}`}
                                                                    >
                                                                        Different
                                                                        price by
                                                                        adults
                                                                    </FieldLabel>
                                                                </FieldContent>
                                                            </Field>
                                                        )}
                                                    </div>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        {open &&
                                            grid.rows.map((row) => (
                                                <TableRow key={row.id}>
                                                    <TableCell className="pl-6 font-medium">
                                                        {row.label}
                                                    </TableCell>
                                                    {columns.map((column) => (
                                                        <TableCell
                                                            key={column.label}
                                                        >
                                                            <InputGroup className="w-36 min-w-32">
                                                                <InputGroupAddon>
                                                                    {currency}
                                                                </InputGroupAddon>
                                                                <InputGroupInput
                                                                    aria-label={`${roomType.name} ${row.label} ${column.label}`}
                                                                    placeholder="0.00"
                                                                    type="number"
                                                                    min="0"
                                                                    max="9999999999.99"
                                                                    step="0.01"
                                                                    inputMode="decimal"
                                                                    value={cellValue(
                                                                        row,
                                                                        column,
                                                                    )}
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        changeCell(
                                                                            roomType.id,
                                                                            row.id,
                                                                            column,
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                />
                                                            </InputGroup>
                                                            <PerPersonHint
                                                                basis={basis}
                                                                row={row}
                                                                value={cellValue(
                                                                    row,
                                                                    column,
                                                                )}
                                                            />
                                                        </TableCell>
                                                    ))}
                                                </TableRow>
                                            ))}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={columns.length + 1}>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <p className="text-sm font-normal whitespace-normal text-muted-foreground">
                                            Leave a price blank to keep what is
                                            saved for that day.
                                            {basis === 'day_use' &&
                                                ' Each hour count has its own price for the room. A saved date replaces the prices of the hours shown here.'}
                                            {basis === 'per_guest' &&
                                                ' The 1 adult price is per adult. Prices for more adults are optional totals for that many adults.'}
                                        </p>
                                        {!restrictions && (
                                            <Button
                                                type="submit"
                                                disabled={form.processing}
                                                focusableWhenDisabled
                                            >
                                                {form.processing && (
                                                    <Spinner data-icon="inline-start" />
                                                )}
                                                {submitLabel}
                                            </Button>
                                        )}
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}
            {restrictions && (
                <>
                    <RestrictionsSection
                        stopSellOnly={basis === 'day_use'}
                        choices={choices}
                        onChange={setChoices}
                        errors={errors}
                    />
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={form.processing || cannotSave}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            {submitLabel}
                        </Button>
                    </div>
                </>
            )}
            {gridErrors.map((message) => (
                <FieldError key={message}>{message}</FieldError>
            ))}
            <UnsavedChangesDialog
                guard={guard}
                name={ratePlan.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}

/**
 * Fills the adult-count rows of one room type from a base adult count. It only fills the fields.
 */
function AutoFill({
    roomType,
    basis,
    rows,
    currency,
    onApply,
}: {
    roomType: RoomType;
    basis: App.Enums.PricingBasis;
    rows: PricingRow[];
    currency: App.Enums.Currency;
    onApply: (fill: (rows: PricingRow[]) => PricingRow[]) => void;
}) {
    if (basis === 'day_use') {
        return (
            <DayUseAutoFill
                roomType={roomType}
                rows={rows}
                currency={currency}
                onApply={onApply}
            />
        );
    }

    if (basis === 'per_guest') {
        return (
            <GuestAutoFill
                roomType={roomType}
                rows={rows}
                currency={currency}
                onApply={onApply}
            />
        );
    }

    return (
        <RoomAutoFill
            roomType={roomType}
            basis={basis}
            rows={rows}
            currency={currency}
            onApply={onApply}
        />
    );
}

/**
 * Under a PerGuest adult-count price, the share of one person, so a room total is never mistaken for a per person price.
 */
function PerPersonHint({
    basis,
    row,
    value,
}: {
    basis: App.Enums.PricingBasis;
    row: PricingRow;
    value: string;
}) {
    const share =
        basis === 'per_guest' && row.kind === 'occupancy' && row.adults
            ? perPersonShare(value, row.adults)
            : null;

    if (share === null) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-muted-foreground tabular-nums">
            {share.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })}{' '}
            each
        </p>
    );
}

/**
 * PerRoom Auto: start from the price of a base adult count. Each step up adds and each step down subtracts.
 */
function RoomAutoFill({
    roomType,
    basis,
    rows,
    currency,
    onApply,
}: {
    roomType: RoomType;
    basis: App.Enums.PricingBasis;
    rows: PricingRow[];
    currency: App.Enums.Currency;
    onApply: (fill: (rows: PricingRow[]) => PricingRow[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const [baseAdults, setBaseAdults] = useState(
        String(Math.min(roomType.baseOccupancy, roomType.maxAdults)),
    );
    const [stepUp, setStepUp] = useState('');
    const [stepDown, setStepDown] = useState('');
    const [unit, setUnit] = useState<AutoFillOptions['unit']>('amount');
    const adultOptions = Array.from(
        { length: roomType.maxAdults },
        (_, index) => ({
            value: String(index + 1),
            label: `${index + 1} ${index === 0 ? 'adult' : 'adults'}`,
        }),
    );
    const seeded = hasSeed(rows, roomType, basis, Number(baseAdults));
    const seedName =
        basis === 'per_room' && Number(baseAdults) === roomType.baseOccupancy
            ? 'room price'
            : `price for ${baseAdults} ${baseAdults === '1' ? 'adult' : 'adults'}`;

    const apply = () => {
        const options: AutoFillOptions = {
            baseAdults: Number(baseAdults),
            stepUp: Number(stepUp) || 0,
            stepDown: Number(stepDown) || 0,
            unit,
        };

        onApply((current) => autoFill(current, roomType, basis, options));
        setOpen(false);
    };

    const stepField = (
        id: string,
        label: string,
        value: string,
        onChange: (value: string) => void,
    ) => (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <InputGroup>
                {unit === 'amount' && (
                    <InputGroupAddon>{currency}</InputGroupAddon>
                )}
                <InputGroupInput
                    id={id}
                    type="number"
                    min="0"
                    step="0.01"
                    inputMode="decimal"
                    placeholder={unit === 'amount' ? '0.00' : '0'}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
                {unit === 'percent' && (
                    <InputGroupAddon align="inline-end">%</InputGroupAddon>
                )}
            </InputGroup>
        </Field>
    );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={<Button type="button" variant="outline" size="sm" />}
            >
                <WandSparkles data-icon="inline-start" />
                Auto
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
                <PopoverHeader>
                    <PopoverTitle>Auto fill prices by adults</PopoverTitle>
                    <PopoverDescription>
                        Uses the price of the base adult count. Each step up
                        adds and each step down subtracts.
                    </PopoverDescription>
                </PopoverHeader>
                <div className="flex flex-col gap-3">
                    {!seeded && (
                        <Alert>
                            <TriangleAlert />
                            <AlertDescription>
                                Enter the {seedName} first. Auto fill starts
                                from it.
                            </AlertDescription>
                        </Alert>
                    )}
                    <Field>
                        <FieldLabel>Base adults</FieldLabel>
                        <EnumSelect
                            aria-label="Base adults"
                            options={adultOptions}
                            value={baseAdults}
                            onValueChange={setBaseAdults}
                        />
                    </Field>
                    <Field>
                        <FieldLabel>Steps are</FieldLabel>
                        <EnumSelect
                            aria-label="Step unit"
                            options={[
                                { value: 'amount', label: 'An amount' },
                                { value: 'percent', label: 'A percentage' },
                            ]}
                            value={unit}
                            onValueChange={(value) =>
                                setUnit(value as AutoFillOptions['unit'])
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        {stepField(
                            `step-up-${roomType.id}`,
                            'Step up',
                            stepUp,
                            setStepUp,
                        )}
                        {stepField(
                            `step-down-${roomType.id}`,
                            'Step down',
                            stepDown,
                            setStepDown,
                        )}
                    </div>
                    <Button
                        type="button"
                        onClick={apply}
                        disabled={!seeded}
                        focusableWhenDisabled
                    >
                        Fill prices
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

/**
 * PerGuest Auto: start from the 1 adult price, which is per person. The per person price moves by one step for
 * each adult after the first, and each row gets the room total.
 */
function GuestAutoFill({
    roomType,
    rows,
    currency,
    onApply,
}: {
    roomType: RoomType;
    rows: PricingRow[];
    currency: App.Enums.Currency;
    onApply: (fill: (rows: PricingRow[]) => PricingRow[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const [change, setChange] =
        useState<GuestAutoFillOptions['change']>('same');
    const [step, setStep] = useState('');
    const [unit, setUnit] = useState<GuestAutoFillOptions['unit']>('amount');
    const seed = rows.find((row) => row.kind === 'amount');
    const seeded = hasSeed(rows, roomType, 'per_guest', 1);
    const seedPrices = [
        ...new Set(seed?.values.filter((value) => value !== '') ?? []),
    ];
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });

    const apply = () => {
        const options: GuestAutoFillOptions = {
            change,
            step: Number(step) || 0,
            unit,
        };

        onApply((current) => autoFillPerGuest(current, options));
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={<Button type="button" variant="outline" size="sm" />}
            >
                <WandSparkles data-icon="inline-start" />
                Auto
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
                <PopoverHeader>
                    <PopoverTitle>Auto fill prices by adults</PopoverTitle>
                    <PopoverDescription>
                        {seeded && seedPrices.length === 1
                            ? `Starts from the 1 adult price: ${money.format(Number(seedPrices[0]))} per person.`
                            : 'Starts from the 1 adult price, which is per person.'}{' '}
                        Each row gets the total for that many adults.
                    </PopoverDescription>
                </PopoverHeader>
                <div className="flex flex-col gap-3">
                    {!seeded && (
                        <Alert>
                            <TriangleAlert />
                            <AlertDescription>
                                Enter the 1 adult price first. Auto fill starts
                                from it.
                            </AlertDescription>
                        </Alert>
                    )}
                    <Field>
                        <FieldLabel>Per person, each extra adult</FieldLabel>
                        <EnumSelect
                            aria-label="Per person change"
                            options={[
                                { value: 'same', label: 'Same price' },
                                { value: 'less', label: 'Less' },
                                { value: 'more', label: 'More' },
                            ]}
                            value={change}
                            onValueChange={(value) =>
                                setChange(
                                    value as GuestAutoFillOptions['change'],
                                )
                            }
                        />
                    </Field>
                    {change !== 'same' && (
                        <div className="grid grid-cols-2 gap-3">
                            <Field>
                                <FieldLabel>Change by</FieldLabel>
                                <EnumSelect
                                    aria-label="Change unit"
                                    options={[
                                        { value: 'amount', label: 'Amount' },
                                        {
                                            value: 'percent',
                                            label: 'Percentage',
                                        },
                                    ]}
                                    value={unit}
                                    onValueChange={(value) =>
                                        setUnit(
                                            value as GuestAutoFillOptions['unit'],
                                        )
                                    }
                                />
                            </Field>
                            <Field>
                                <FieldLabel
                                    htmlFor={`guest-step-${roomType.id}`}
                                >
                                    Step
                                </FieldLabel>
                                <InputGroup>
                                    {unit === 'amount' && (
                                        <InputGroupAddon>
                                            {currency}
                                        </InputGroupAddon>
                                    )}
                                    <InputGroupInput
                                        id={`guest-step-${roomType.id}`}
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="decimal"
                                        placeholder={
                                            unit === 'amount' ? '0.00' : '0'
                                        }
                                        value={step}
                                        onChange={(event) =>
                                            setStep(event.target.value)
                                        }
                                    />
                                    {unit === 'percent' && (
                                        <InputGroupAddon align="inline-end">
                                            %
                                        </InputGroupAddon>
                                    )}
                                </InputGroup>
                            </Field>
                        </div>
                    )}
                    <Button
                        type="button"
                        onClick={apply}
                        disabled={!seeded}
                        focusableWhenDisabled
                    >
                        Fill prices
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

/**
 * Day use Auto: the first block (hours and price), the change for each extra hour, and an optional cap. It fills the
 * hour rows for every weekday column. It only fills the fields.
 */
function DayUseAutoFill({
    roomType,
    rows,
    currency,
    onApply,
}: {
    roomType: RoomType;
    rows: PricingRow[];
    currency: App.Enums.Currency;
    onApply: (fill: (rows: PricingRow[]) => PricingRow[]) => void;
}) {
    const hourRows = rows.filter((row) => row.hours !== null);
    const [open, setOpen] = useState(false);
    const [firstHours, setFirstHours] = useState(
        String(hourRows[0]?.hours ?? 1),
    );
    const [firstPrice, setFirstPrice] = useState('');
    const [direction, setDirection] =
        useState<DayUseAutoFillOptions['direction']>('more');
    const [step, setStep] = useState('');
    const [unit, setUnit] = useState<DayUseAutoFillOptions['unit']>('amount');
    const [cap, setCap] = useState('');
    const valid = firstPrice !== '' && Number(firstPrice) >= 0;

    const apply = () => {
        const options: DayUseAutoFillOptions = {
            firstHours: Number(firstHours),
            firstPrice: Number(firstPrice),
            direction,
            step: Number(step) || 0,
            unit,
            cap: cap === '' ? null : Number(cap),
        };

        onApply((current) => autoFillDayUse(current, options));
        setOpen(false);
    };

    const moneyField = (
        id: string,
        label: string,
        value: string,
        onChange: (value: string) => void,
        addon: 'currency' | 'unit',
    ) => (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <InputGroup>
                {(addon === 'currency' || unit === 'amount') && (
                    <InputGroupAddon>{currency}</InputGroupAddon>
                )}
                <InputGroupInput
                    id={id}
                    type="number"
                    min="0"
                    step="0.01"
                    inputMode="decimal"
                    placeholder={
                        addon === 'unit' && unit === 'percent' ? '0' : '0.00'
                    }
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
                {addon === 'unit' && unit === 'percent' && (
                    <InputGroupAddon align="inline-end">%</InputGroupAddon>
                )}
            </InputGroup>
        </Field>
    );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={<Button type="button" variant="outline" size="sm" />}
            >
                <WandSparkles data-icon="inline-start" />
                Auto
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
                <PopoverHeader>
                    <PopoverTitle>Auto fill prices by hours</PopoverTitle>
                    <PopoverDescription>
                        Sets the first block, then changes the price for each
                        extra hour. Every weekday column is filled.
                    </PopoverDescription>
                </PopoverHeader>
                <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-2 gap-3">
                        <Field>
                            <FieldLabel>First block</FieldLabel>
                            <EnumSelect
                                aria-label="First block hours"
                                options={hourRows.map((row) => ({
                                    value: String(row.hours),
                                    label: row.label,
                                }))}
                                value={firstHours}
                                onValueChange={setFirstHours}
                            />
                        </Field>
                        {moneyField(
                            `day-use-first-${roomType.id}`,
                            'Price',
                            firstPrice,
                            setFirstPrice,
                            'currency',
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field>
                            <FieldLabel>Each extra hour</FieldLabel>
                            <EnumSelect
                                aria-label="Change direction"
                                options={[
                                    { value: 'more', label: 'Adds' },
                                    { value: 'less', label: 'Takes off' },
                                ]}
                                value={direction}
                                onValueChange={(value) =>
                                    setDirection(
                                        value as DayUseAutoFillOptions['direction'],
                                    )
                                }
                            />
                        </Field>
                        <Field>
                            <FieldLabel>Change is</FieldLabel>
                            <EnumSelect
                                aria-label="Change unit"
                                options={[
                                    { value: 'amount', label: 'An amount' },
                                    { value: 'percent', label: 'A percentage' },
                                ]}
                                value={unit}
                                onValueChange={(value) =>
                                    setUnit(
                                        value as DayUseAutoFillOptions['unit'],
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        {moneyField(
                            `day-use-step-${roomType.id}`,
                            'Step',
                            step,
                            setStep,
                            'unit',
                        )}
                        {moneyField(
                            `day-use-cap-${roomType.id}`,
                            'Never more than',
                            cap,
                            setCap,
                            'currency',
                        )}
                    </div>
                    <Button
                        type="button"
                        onClick={apply}
                        disabled={!valid}
                        focusableWhenDisabled
                    >
                        Fill prices
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
