import { useState } from 'react';
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
import { Switch } from '@/components/ui/switch';
import {
    adultCounts,
    cellFormProblem,
    cellFormValues,
    isCellFormDirty,
} from './cell-form';
import type { CellFormValues, CellTarget } from './cell-form';
import type { PendingChange } from './pending-changes';
import { formatAmount, nightsLabel } from './calendar-model';

type Cell = App.Data.Rates.RateCalendarCellData;
type RoomType = App.Data.Rates.RateCalendarRoomTypeData;
type Plan = App.Data.Rates.RateCalendarPlanData;

/** The cell being edited. */
export type EditingCell = {
    roomType: RoomType;
    plan: Plan;
    /** The saved cell, not the staged one. */
    cell: Cell | undefined;
    target: CellTarget;
    /** The values already staged for this date, if any. */
    staged: CellFormValues | undefined;
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
    onApply,
}: {
    editing: EditingCell;
    currency: string;
    onApply: (change: PendingChange) => void;
}) {
    const { roomType, plan, cell, target, staged } = editing;
    const isDayUse = plan.pricingBasis === 'day_use';
    const ownPrice =
        !plan.derived && plan.pricingBasis !== 'package' && !isDayUse;
    const initial = cellFormValues(cell, ownPrice);
    const [data, setData] = useState<CellFormValues>(staged ?? initial);
    const setField = <K extends keyof CellFormValues>(
        key: K,
        value: CellFormValues[K],
    ) => setData((current) => ({ ...current, [key]: value }));
    const price = PRICE_LABELS[plan.pricingBasis];
    const problem = cellFormProblem(initial, data);
    const dirty = isCellFormDirty(initial, data);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (problem) {
            return;
        }

        onApply({
            target,
            roomTypeName: roomType.name,
            planName: plan.name,
            initial,
            current: data,
            ownPrice,
            dayUse: isDayUse,
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                {ownPrice && price ? (
                    <FieldSet>
                        <FieldLegend>Price</FieldLegend>
                        <Field>
                            <FieldLabel htmlFor="cell-amount">
                                {price.label}
                            </FieldLabel>
                            <MoneyInput
                                id="cell-amount"
                                value={data.amount}
                                currency={currency}
                                onChange={(amount) =>
                                    setField('amount', amount)
                                }
                            />
                            <FieldDescription>
                                {price.hint(roomType)}
                            </FieldDescription>
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
                                                                data.occupancy[
                                                                    String(
                                                                        adults,
                                                                    )
                                                                ] ?? ''
                                                            }
                                                            currency={currency}
                                                            onChange={(
                                                                amount,
                                                            ) =>
                                                                setField(
                                                                    'occupancy',
                                                                    {
                                                                        ...data.occupancy,
                                                                        [String(
                                                                            adults,
                                                                        )]:
                                                                            amount,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                );
                                            },
                                        )}
                                    </div>
                                </FieldSet>
                            )}
                        {problem && <FieldError>{problem}</FieldError>}
                    </FieldSet>
                ) : isDayUse ? (
                    <FieldSet>
                        <FieldLegend>Price</FieldLegend>
                        {cell && cell.hours.length > 0 ? (
                            <ul className="flex flex-col gap-1 text-sm tabular-nums">
                                {cell.hours.map((price) => (
                                    <li
                                        key={price.hours}
                                        className="flex justify-between gap-4"
                                    >
                                        <span>
                                            {price.hours}{' '}
                                            {price.hours === 1
                                                ? 'hour'
                                                : 'hours'}
                                        </span>
                                        <span>
                                            {formatAmount(price.amount)}{' '}
                                            {currency}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm">
                                No hour prices on this date.
                            </p>
                        )}
                        <FieldDescription>
                            Day use prices are set on the plan's Pricing tab in
                            Settings, or in Bulk edit. You can still stop
                            selling this date.
                        </FieldDescription>
                    </FieldSet>
                ) : (
                    <FieldSet>
                        <FieldLegend>Price</FieldLegend>
                        <p className="text-sm">
                            {plan.derived
                                ? `${cell?.amount ? formatAmount(cell.amount) : 'No price'} ${currency}, calculated from ${plan.followsName}.`
                                : cell?.amount
                                  ? `${formatAmount(cell.amount)} ${currency} for the ${nightsLabel(plan.fixedNights ?? 0)}, ${plan.packageUnit === 'per_room' ? 'for the room' : 'for each adult'}, for guests arriving on this date.${cell.arrivalNotAllowed ? ' The package cannot start on this weekday.' : ''}`
                                  : 'No package price covers this arrival date.'}
                        </p>
                        <FieldDescription>
                            {plan.derived
                                ? `To change it, change the price of ${plan.followsName} or how this plan follows it.`
                                : "Package prices are set on the package's Pricing tab in Settings. You can still set the selling restrictions for this date."}
                        </FieldDescription>
                    </FieldSet>
                )}
                <FieldSet>
                    <FieldLegend>Restrictions</FieldLegend>
                    <SwitchField
                        id="cell-stop-sell"
                        label="Stop sell"
                        description="Nothing can be sold on this night."
                        checked={data.stopSell}
                        onChange={(checked) => setField('stopSell', checked)}
                    />
                    {!isDayUse && (
                        <>
                            <SwitchField
                                id="cell-cta"
                                label="Closed to arrival"
                                description="No stay can start on this date."
                                checked={data.closedToArrival}
                                onChange={(checked) =>
                                    setField('closedToArrival', checked)
                                }
                            />
                            <SwitchField
                                id="cell-ctd"
                                label="Closed to departure"
                                description="No stay can end on this date."
                                checked={data.closedToDeparture}
                                onChange={(checked) =>
                                    setField('closedToDeparture', checked)
                                }
                            />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field>
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
                                            value={data.minStay}
                                            onChange={(event) =>
                                                setField(
                                                    'minStay',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputGroupAddon align="inline-end">
                                            nights
                                        </InputGroupAddon>
                                    </InputGroup>
                                </Field>
                                <Field>
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
                                            value={data.maxStay}
                                            onChange={(event) =>
                                                setField(
                                                    'maxStay',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputGroupAddon align="inline-end">
                                            nights
                                        </InputGroupAddon>
                                    </InputGroup>
                                </Field>
                            </div>
                            <FieldDescription>
                                Leave a stay limit blank for no limit. Min and
                                max stay are checked from the arrival date.
                            </FieldDescription>
                        </>
                    )}
                </FieldSet>
            </FieldGroup>
            <SheetFooter>
                <SheetClose render={<Button type="button" variant="outline" />}>
                    Cancel
                </SheetClose>
                <Button
                    type="submit"
                    disabled={(!dirty && staged === undefined) || !!problem}
                    focusableWhenDisabled
                >
                    Apply
                </Button>
            </SheetFooter>
        </form>
    );
}

/**
 * The editor for one date of one plan, opened from a cell of the rate calendar. Apply stages the edit on the
 * calendar, which saves all staged edits together.
 */
export function RateCellSheet({
    editing,
    currency,
    onApply,
    onClose,
}: {
    editing: EditingCell | null;
    currency: string;
    onApply: (change: PendingChange) => void;
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
                        {editing ? longDate(editing.target.date) : ''}. Apply
                        keeps the change on the calendar until you save.
                    </SheetDescription>
                </SheetHeader>
                {editing && (
                    <CellForm
                        key={`${editing.plan.id}-${editing.roomType.id}-${editing.target.date}`}
                        editing={editing}
                        currency={currency}
                        onApply={(change) => {
                            onApply(change);
                            onClose();
                        }}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}
