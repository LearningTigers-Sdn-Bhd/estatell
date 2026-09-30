import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { update } from '@/routes/rates/calendar';
import {
    adultCounts,
    buildCellPayload,
    cellFormProblem,
    cellFormValues,
    isCellFormDirty,
} from './cell-form';
import type { CellFormValues, CellTarget } from './cell-form';
import { formatAmount } from './calendar-model';

type Cell = App.Data.Rates.RateCalendarCellData;
type RoomType = App.Data.Rates.RateCalendarRoomTypeData;
type Plan = App.Data.Rates.RateCalendarPlanData;

/** The cell being edited. */
export type EditingCell = {
    roomType: RoomType;
    plan: Plan;
    cell: Cell | undefined;
    target: CellTarget;
};

const PRICE_LABELS: Record<
    string,
    { label: string; hint: (roomType: RoomType) => string }
> = {
    per_room: {
        label: 'Room price',
        hint: (roomType) =>
            `For ${roomType.baseOccupancy} ${roomType.baseOccupancy === 1 ? 'adult' : 'adults'}.`,
    },
    per_guest: { label: 'Price per adult', hint: () => 'For one adult.' },
    per_bed: { label: 'Price per bed', hint: () => 'For one bed.' },
};

function longDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function MoneyInput({
    id,
    value,
    currency,
    onChange,
    invalid,
    label,
}: {
    id: string;
    value: string;
    currency: string;
    onChange: (value: string) => void;
    invalid?: boolean;
    label?: string;
}) {
    return (
        <InputGroup>
            <InputGroupAddon>{currency}</InputGroupAddon>
            <InputGroupInput
                id={id}
                aria-label={label}
                aria-invalid={invalid}
                type="number"
                min="0"
                max="9999999999.99"
                step="0.01"
                inputMode="decimal"
                placeholder="0.00"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </InputGroup>
    );
}

function SwitchField({
    id,
    label,
    description,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <Field orientation="horizontal">
            <FieldContent>
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
                <FieldDescription>{description}</FieldDescription>
            </FieldContent>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </Field>
    );
}

function CellForm({
    editing,
    currency,
    onDone,
}: {
    editing: EditingCell;
    currency: string;
    onDone: () => void;
}) {
    const { roomType, plan, cell, target } = editing;
    const ownPrice = !plan.derived && plan.pricingBasis !== 'package';
    const initial = cellFormValues(cell, ownPrice);
    const form = useForm<CellFormValues>(initial);
    const price = PRICE_LABELS[plan.pricingBasis];
    const problem = cellFormProblem(initial, form.data);
    const dirty = isCellFormDirty(initial, form.data);
    const error = (key: string): string | undefined =>
        (form.errors as Record<string, string | undefined>)[key];

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (problem) {
            return;
        }

        form.transform(() =>
            buildCellPayload(target, initial, form.data, ownPrice),
        );
        form.put(update.url({ ratePlan: plan.id }), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                {ownPrice && price ? (
                    <FieldSet>
                        <FieldLegend>Price</FieldLegend>
                        <Field
                            data-invalid={!!error('room_types.0.days.0.amount')}
                        >
                            <FieldLabel htmlFor="cell-amount">
                                {price.label}
                            </FieldLabel>
                            <MoneyInput
                                id="cell-amount"
                                value={form.data.amount}
                                currency={currency}
                                invalid={!!error('room_types.0.days.0.amount')}
                                onChange={(amount) =>
                                    form.setData('amount', amount)
                                }
                            />
                            <FieldDescription>
                                {price.hint(roomType)}
                            </FieldDescription>
                            <FieldError>
                                {error('room_types.0.days.0.amount')}
                            </FieldError>
                        </Field>
                        {plan.pricingBasis !== 'per_bed' &&
                            roomType.maxAdults > 0 && (
                                <FieldSet>
                                    <FieldLegend variant="label">
                                        Price by adults (optional)
                                    </FieldLegend>
                                    <FieldDescription>
                                        {plan.pricingBasis === 'per_guest'
                                            ? 'The total for that many adults. Leave blank to use the price per adult.'
                                            : 'The room price for that many adults. Leave blank to use the room price.'}
                                    </FieldDescription>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {adultCounts(roomType.maxAdults).map(
                                            (adults) => {
                                                const errorKey = `room_types.0.days.0.occupancy.${adults - 1}`;

                                                return (
                                                    <Field key={adults}>
                                                        <FieldLabel
                                                            htmlFor={`cell-adults-${adults}`}
                                                        >
                                                            {adults}{' '}
                                                            {adults === 1
                                                                ? 'adult'
                                                                : 'adults'}
                                                        </FieldLabel>
                                                        <MoneyInput
                                                            id={`cell-adults-${adults}`}
                                                            value={
                                                                form.data
                                                                    .occupancy[
                                                                    String(
                                                                        adults,
                                                                    )
                                                                ] ?? ''
                                                            }
                                                            currency={currency}
                                                            invalid={
                                                                !!(
                                                                    error(
                                                                        `${errorKey}.amount`,
                                                                    ) ??
                                                                    error(
                                                                        `${errorKey}.adults`,
                                                                    )
                                                                )
                                                            }
                                                            onChange={(
                                                                amount,
                                                            ) =>
                                                                form.setData(
                                                                    'occupancy',
                                                                    {
                                                                        ...form
                                                                            .data
                                                                            .occupancy,
                                                                        [String(
                                                                            adults,
                                                                        )]:
                                                                            amount,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        <FieldError>
                                                            {error(
                                                                `${errorKey}.amount`,
                                                            ) ??
                                                                error(
                                                                    `${errorKey}.adults`,
                                                                )}
                                                        </FieldError>
                                                    </Field>
                                                );
                                            },
                                        )}
                                    </div>
                                </FieldSet>
                            )}
                        {problem && <FieldError>{problem}</FieldError>}
                    </FieldSet>
                ) : (
                    <FieldSet>
                        <FieldLegend>Price</FieldLegend>
                        <p className="text-sm">
                            {plan.derived
                                ? `${cell?.amount ? formatAmount(cell.amount) : 'No price'} ${currency}, calculated from ${plan.followsName}.`
                                : 'Package prices are set on the package.'}
                        </p>
                        <FieldDescription>
                            {plan.derived
                                ? `To change it, change the price of ${plan.followsName} or how this plan follows it.`
                                : 'You can still set the selling restrictions for this date.'}
                        </FieldDescription>
                    </FieldSet>
                )}
                <FieldSet>
                    <FieldLegend>Restrictions</FieldLegend>
                    <SwitchField
                        id="cell-stop-sell"
                        label="Stop sell"
                        description="Nothing can be sold on this night."
                        checked={form.data.stopSell}
                        onChange={(checked) =>
                            form.setData('stopSell', checked)
                        }
                    />
                    <SwitchField
                        id="cell-cta"
                        label="Closed to arrival"
                        description="No stay can start on this date."
                        checked={form.data.closedToArrival}
                        onChange={(checked) =>
                            form.setData('closedToArrival', checked)
                        }
                    />
                    <SwitchField
                        id="cell-ctd"
                        label="Closed to departure"
                        description="No stay can end on this date."
                        checked={form.data.closedToDeparture}
                        onChange={(checked) =>
                            form.setData('closedToDeparture', checked)
                        }
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field data-invalid={!!error('restrictions.min_stay')}>
                            <FieldLabel htmlFor="cell-min-stay">
                                Min stay
                            </FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="cell-min-stay"
                                    type="number"
                                    min="1"
                                    max="365"
                                    step="1"
                                    inputMode="numeric"
                                    aria-invalid={
                                        !!error('restrictions.min_stay')
                                    }
                                    value={form.data.minStay}
                                    onChange={(event) =>
                                        form.setData(
                                            'minStay',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputGroupAddon align="inline-end">
                                    nights
                                </InputGroupAddon>
                            </InputGroup>
                            <FieldError>
                                {error('restrictions.min_stay')}
                            </FieldError>
                        </Field>
                        <Field data-invalid={!!error('restrictions.max_stay')}>
                            <FieldLabel htmlFor="cell-max-stay">
                                Max stay
                            </FieldLabel>
                            <InputGroup>
                                <InputGroupInput
                                    id="cell-max-stay"
                                    type="number"
                                    min="1"
                                    max="365"
                                    step="1"
                                    inputMode="numeric"
                                    aria-invalid={
                                        !!error('restrictions.max_stay')
                                    }
                                    value={form.data.maxStay}
                                    onChange={(event) =>
                                        form.setData(
                                            'maxStay',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputGroupAddon align="inline-end">
                                    nights
                                </InputGroupAddon>
                            </InputGroup>
                            <FieldError>
                                {error('restrictions.max_stay')}
                            </FieldError>
                        </Field>
                    </div>
                    <FieldDescription>
                        Leave a stay limit blank for no limit. Min and max stay
                        are checked from the arrival date.
                    </FieldDescription>
                </FieldSet>
            </FieldGroup>
            <SheetFooter>
                <SheetClose render={<Button type="button" variant="outline" />}>
                    Cancel
                </SheetClose>
                <Button
                    type="submit"
                    disabled={!dirty || !!problem || form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save
                </Button>
            </SheetFooter>
        </form>
    );
}

/**
 * The editor for one date of one plan, opened from a cell of the rate calendar. It saves through
 * `rates.calendar.update`.
 */
export function RateCellSheet({
    editing,
    currency,
    onClose,
}: {
    editing: EditingCell | null;
    currency: string;
    onClose: () => void;
}) {
    return (
        <Sheet
            open={editing !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent className="data-[side=right]:sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>
                        {editing
                            ? `${editing.roomType.name} · ${editing.plan.name}`
                            : 'Edit rate'}
                    </SheetTitle>
                    <SheetDescription>
                        {editing ? longDate(editing.target.date) : ''}
                    </SheetDescription>
                </SheetHeader>
                {editing && (
                    <CellForm
                        key={`${editing.plan.id}-${editing.roomType.id}-${editing.target.date}`}
                        editing={editing}
                        currency={currency}
                        onDone={onClose}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}
