import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/rate-plans/pricing/package';

type RatePlan = App.Data.Rates.RatePlanData;
type RoomType = App.Data.Rates.PackagePriceRoomTypeData;
type Price = App.Data.Rates.PackagePriceData;
type WeekdayOption = { value: string; label: string; shortLabel: string };
type Row = {
    id: number | null;
    room_type_id: number;
    arrival_from: string;
    arrival_to: string;
    amount: string;
};

/**
 * The stay price of a package by room type and arrival period, and the weekdays guests can arrive on.
 */
export function PackagePricingForm({
    ratePlan,
    roomTypes,
    prices,
    weekdays,
    weekdayOptions,
    currency,
    following = false,
}: {
    ratePlan: RatePlan;
    roomTypes: RoomType[];
    prices: Price[];
    weekdays: string[];
    weekdayOptions: WeekdayOption[];
    currency: App.Enums.Currency;
    /** A package that follows another has no prices of its own, so only its arrival days show. */
    following?: boolean;
}) {
    const form = useForm<{ prices: Row[]; weekdays: string[] }>({
        prices: prices.map((price) => ({
            id: price.id,
            room_type_id: price.roomTypeId,
            arrival_from: price.arrivalFrom,
            arrival_to: price.arrivalTo,
            amount: price.amount,
        })),
        weekdays,
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.prices;
    const errors = form.errors as Record<string, string | undefined>;
    const perGuest = ratePlan.packageUnit === 'per_guest';
    const nights = `${ratePlan.fixedNights} ${ratePlan.fixedNights === 1 ? 'night' : 'nights'}`;

    const setRows = (next: Row[]) => form.setData('prices', next);

    const setRow = (index: number, values: Partial<Row>) => {
        setRows(
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const addRow = (roomTypeId: number) => {
        setRows([
            ...rows,
            {
                id: null,
                room_type_id: roomTypeId,
                arrival_from: '',
                arrival_to: '',
                amount: '',
            },
        ]);
    };

    const removeRow = (index: number) => {
        setRows(rows.filter((_, position) => position !== index));
    };

    const toggleWeekday = (value: string, checked: boolean) => {
        form.setData(
            'weekdays',
            checked
                ? [...form.data.weekdays, value]
                : form.data.weekdays.filter((day) => day !== value),
        );
    };

    const save = (onSaved?: () => void) => {
        form.put(update.url({ ratePlan: ratePlan.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    if (roomTypes.length === 0 && !following) {
        return (
            <EmptyState
                title="No room types"
                description="Choose the room types this package sells on the Room types tab, then set their prices here."
            />
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <FieldSet>
                <FieldLegend variant="label">Arrival days</FieldLegend>
                <FieldDescription>
                    Guests can start this package on the ticked days. Tick all
                    seven to allow any day.
                </FieldDescription>
                <div className="flex flex-wrap gap-x-5 gap-y-3">
                    {weekdayOptions.map((day) => (
                        <Field
                            key={day.value}
                            orientation="horizontal"
                            className="w-auto"
                        >
                            <Checkbox
                                id={`weekday-${day.value}`}
                                checked={form.data.weekdays.includes(day.value)}
                                onCheckedChange={(checked) =>
                                    toggleWeekday(day.value, checked === true)
                                }
                            />
                            <FieldLabel htmlFor={`weekday-${day.value}`}>
                                {day.shortLabel}
                            </FieldLabel>
                        </Field>
                    ))}
                </div>
                <FieldError>{errors.weekdays}</FieldError>
            </FieldSet>
            <FieldError>{errors.prices}</FieldError>
            {(following ? [] : roomTypes).map((roomType) => {
                const group = rows
                    .map((row, index) => ({ row, index }))
                    .filter(({ row }) => row.room_type_id === roomType.id);

                return (
                    <section key={roomType.id} className="flex flex-col gap-3">
                        <div>
                            <h3 className="text-sm font-medium">
                                {roomType.name}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                Price of the {nights}{' '}
                                {perGuest
                                    ? roomType.sellUnitLabel === 'Bed'
                                        ? 'for each guest, one guest to a bed'
                                        : 'for each adult'
                                    : 'for the room'}
                                , by arrival date.
                            </p>
                        </div>
                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                {group.length > 0 && (
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Arrival dates</TableHead>
                                            <TableHead>Price</TableHead>
                                            <TableHead className="w-12">
                                                <span className="sr-only">
                                                    Remove
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                )}
                                <TableBody>
                                    {group.map(({ row, index }) => (
                                        <TableRow
                                            key={row.id ?? `new-${index}`}
                                        >
                                            <TableCell className="align-top">
                                                <DateRangePicker
                                                    className="w-64"
                                                    months={2}
                                                    unit="day"
                                                    placeholder="Pick arrival dates"
                                                    from={row.arrival_from}
                                                    to={row.arrival_to}
                                                    onChange={(from, to) =>
                                                        setRow(index, {
                                                            arrival_from: from,
                                                            arrival_to: to,
                                                        })
                                                    }
                                                    aria-invalid={
                                                        !!errors[
                                                            `prices.${index}.arrival_from`
                                                        ] ||
                                                        !!errors[
                                                            `prices.${index}.arrival_to`
                                                        ]
                                                    }
                                                />
                                                <FieldError>
                                                    {errors[
                                                        `prices.${index}.arrival_from`
                                                    ] ??
                                                        errors[
                                                            `prices.${index}.arrival_to`
                                                        ]}
                                                </FieldError>
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <InputGroup className="w-44">
                                                    <InputGroupAddon>
                                                        {currency}
                                                    </InputGroupAddon>
                                                    <InputGroupInput
                                                        aria-label="Package price"
                                                        type="number"
                                                        min="0"
                                                        max="9999999999.99"
                                                        step="0.01"
                                                        inputMode="decimal"
                                                        placeholder="0.00"
                                                        value={row.amount}
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                amount: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!errors[
                                                                `prices.${index}.amount`
                                                            ]
                                                        }
                                                    />
                                                </InputGroup>
                                                <FieldError>
                                                    {
                                                        errors[
                                                            `prices.${index}.amount`
                                                        ]
                                                    }
                                                </FieldError>
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Remove period"
                                                    onClick={() =>
                                                        removeRow(index)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow>
                                        <TableCell colSpan={3}>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    addRow(roomType.id)
                                                }
                                            >
                                                <Plus data-icon="inline-start" />
                                                Add period
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                );
            })}
            <footer className="flex justify-end gap-2">
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {following ? 'Save arrival days' : 'Save pricing'}
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={ratePlan.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
