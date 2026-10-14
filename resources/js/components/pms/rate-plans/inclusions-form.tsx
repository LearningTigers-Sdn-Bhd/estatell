import { useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
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
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/rate-plans/inclusions';

type RatePlan = App.Data.Rates.RatePlanData;
type Inclusion = App.Data.Rates.RatePlanInclusionData;
type ChargeItem = App.Data.Rates.RatePlanInclusionChargeItemData;
type MealPeriod = App.Data.Rates.RatePlanInclusionMealPeriodData;
type Option = { value: string; label: string };
type Row = {
    id: number | null;
    charge_item_id: string;
    meal_period_id: string;
    charging_unit: string;
    quantity: string;
    allocated_amount: string;
    starts_on: string;
    ends_on: string;
};

const noMealPeriod = 'No meal';

/**
 * What a plan's price includes: meals, transfers, activities. Each row moves part of the room price to a charge item.
 */
export function InclusionsForm({
    ratePlan,
    inclusions,
    chargeItems,
    mealPeriods,
    chargingUnits,
    startOptions,
    endOptions,
    currency,
}: {
    ratePlan: RatePlan;
    inclusions: Inclusion[];
    chargeItems: ChargeItem[];
    mealPeriods: MealPeriod[];
    chargingUnits: Option[];
    startOptions: Option[];
    endOptions: Option[];
    currency: App.Data.Property.CurrencyData;
}) {
    const form = useForm<{ inclusions: Row[] }>({
        inclusions: inclusions.map((inclusion) => ({
            id: inclusion.id,
            charge_item_id: String(inclusion.chargeItemId),
            meal_period_id:
                inclusion.mealPeriodId === null
                    ? ''
                    : String(inclusion.mealPeriodId),
            charging_unit: inclusion.chargingUnit,
            quantity: String(inclusion.quantity),
            allocated_amount: inclusion.allocatedAmount,
            starts_on: inclusion.startsOn,
            ends_on: inclusion.endsOn,
        })),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.inclusions;
    const errors = form.errors as Record<string, string | undefined>;
    const error = (index: number, field: keyof Row) =>
        errors[`inclusions.${index}.${field}`];

    const chargeItemOptions: Option[] = chargeItems.map((chargeItem) => ({
        value: String(chargeItem.id),
        label: chargeItem.active
            ? chargeItem.name
            : `${chargeItem.name} (inactive)`,
    }));
    const mealPeriodOptions: Option[] = mealPeriods.map((mealPeriod) => ({
        value: String(mealPeriod.id),
        label: mealPeriod.active
            ? mealPeriod.name
            : `${mealPeriod.name} (inactive)`,
    }));
    const firstActive = chargeItems.find((chargeItem) => chargeItem.active);

    const setRows = (next: Row[]) => form.setData('inclusions', next);

    const setRow = (index: number, values: Partial<Row>) => {
        setRows(
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const pickChargeItem = (index: number, value: string) => {
        const chargeItem = chargeItems.find(
            (item) => String(item.id) === value,
        );

        setRow(index, {
            charge_item_id: value,
            ...(chargeItem && {
                charging_unit: chargeItem.chargingUnit,
                allocated_amount: chargeItem.defaultAmount ?? '',
            }),
        });
    };

    const pickMealPeriod = (index: number, value: string) => {
        const mealPeriod = mealPeriods.find(
            (item) => String(item.id) === value,
        );

        setRow(index, {
            meal_period_id: value,
            ...(mealPeriod && {
                starts_on: mealPeriod.defaultStartsOn,
                ends_on: mealPeriod.defaultEndsOn,
            }),
        });
    };

    const addRow = () => {
        setRows([
            ...rows,
            {
                id: null,
                charge_item_id: firstActive ? String(firstActive.id) : '',
                meal_period_id: '',
                charging_unit: firstActive?.chargingUnit ?? 'per_guest_night',
                quantity: '1',
                allocated_amount: firstActive?.defaultAmount ?? '',
                starts_on: 'arrival_day',
                ends_on: 'departure_day',
            },
        ]);
    };

    const removeRow = (index: number) => {
        setRows(rows.filter((_, position) => position !== index));
    };

    const moveRow = (index: number, step: -1 | 1) => {
        const next = [...rows];
        [next[index], next[index + step]] = [next[index + step], next[index]];
        setRows(next);
    };

    const addButton = (
        <Button
            type="button"
            variant="outline"
            onClick={addRow}
            disabled={!firstActive}
        >
            <Plus data-icon="inline-start" />
            Add inclusion
        </Button>
    );

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

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            {rows.length === 0 ? (
                <EmptyState
                    title="No inclusions"
                    description={
                        firstActive
                            ? 'Add a meal, transfer or activity that the price includes, such as breakfast for each guest-night.'
                            : 'Add a charge item first. Inclusions post to charge items.'
                    }
                    action={addButton}
                />
            ) : (
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Charge item</TableHead>
                                <TableHead>Meal period</TableHead>
                                <TableHead>Charged</TableHead>
                                <TableHead>Quantity</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead>Starts</TableHead>
                                <TableHead>Ends</TableHead>
                                <TableHead className="w-32">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => (
                                <TableRow key={row.id ?? `new-${index}`}>
                                    <TableCell className="align-top">
                                        <EnumSelect
                                            aria-label="Charge item"
                                            className="w-48"
                                            options={chargeItemOptions}
                                            value={row.charge_item_id}
                                            onValueChange={(value) =>
                                                pickChargeItem(index, value)
                                            }
                                            aria-invalid={
                                                !!error(index, 'charge_item_id')
                                            }
                                        />
                                        <FieldError>
                                            {error(index, 'charge_item_id')}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <EnumSelect
                                            aria-label="Meal period"
                                            className="w-40"
                                            options={mealPeriodOptions}
                                            allLabel={noMealPeriod}
                                            value={row.meal_period_id}
                                            onValueChange={(value) =>
                                                pickMealPeriod(index, value)
                                            }
                                            aria-invalid={
                                                !!error(index, 'meal_period_id')
                                            }
                                        />
                                        <FieldError>
                                            {error(index, 'meal_period_id')}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <EnumSelect
                                            aria-label="Charging unit"
                                            className="w-44"
                                            options={chargingUnits}
                                            value={row.charging_unit}
                                            onValueChange={(value) =>
                                                setRow(index, {
                                                    charging_unit: value,
                                                })
                                            }
                                            aria-invalid={
                                                !!error(index, 'charging_unit')
                                            }
                                        />
                                        <FieldError>
                                            {error(index, 'charging_unit')}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <InputGroup className="w-24">
                                            <InputGroupInput
                                                aria-label="Quantity"
                                                type="number"
                                                min="1"
                                                max="99"
                                                inputMode="numeric"
                                                value={row.quantity}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!error(index, 'quantity')
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError>
                                            {error(index, 'quantity')}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <InputGroup className="w-36">
                                            <InputGroupAddon>
                                                {currency.code}
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                aria-label="Amount per unit"
                                                type="number"
                                                min="0"
                                                max="9999999999.99"
                                                step="0.01"
                                                inputMode="decimal"
                                                placeholder="0.00"
                                                value={row.allocated_amount}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        allocated_amount:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!error(
                                                        index,
                                                        'allocated_amount',
                                                    )
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError>
                                            {error(index, 'allocated_amount')}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <EnumSelect
                                            aria-label="Starts on"
                                            className="w-44"
                                            options={startOptions}
                                            value={row.starts_on}
                                            onValueChange={(value) =>
                                                setRow(index, {
                                                    starts_on: value,
                                                })
                                            }
                                            aria-invalid={
                                                !!error(index, 'starts_on')
                                            }
                                        />
                                        <FieldError>
                                            {error(index, 'starts_on')}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <EnumSelect
                                            aria-label="Ends on"
                                            className="w-52"
                                            options={endOptions}
                                            value={row.ends_on}
                                            onValueChange={(value) =>
                                                setRow(index, {
                                                    ends_on: value,
                                                })
                                            }
                                            aria-invalid={
                                                !!error(index, 'ends_on')
                                            }
                                        />
                                        <FieldError>
                                            {error(index, 'ends_on')}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <div className="flex gap-1">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Move up"
                                                disabled={index === 0}
                                                onClick={() =>
                                                    moveRow(index, -1)
                                                }
                                            >
                                                <ArrowUp />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Move down"
                                                disabled={
                                                    index === rows.length - 1
                                                }
                                                onClick={() =>
                                                    moveRow(index, 1)
                                                }
                                            >
                                                <ArrowDown />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Remove inclusion"
                                                onClick={() => removeRow(index)}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={8}>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <p className="text-sm font-normal whitespace-normal text-muted-foreground">
                                            The amount moves from the room price
                                            to the charge item for each unit.
                                            Per stay, per item and per guest
                                            post once, on the first night. The
                                            dates set the meals the kitchen
                                            prepares.
                                        </p>
                                        {addButton}
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}
            <FieldError>{errors.inclusions}</FieldError>
            <footer className="flex justify-end gap-2">
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save inclusions
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
