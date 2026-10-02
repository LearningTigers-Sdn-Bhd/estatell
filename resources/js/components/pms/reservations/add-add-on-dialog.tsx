import { useForm } from '@inertiajs/react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { FormError } from '@/components/pms/reservations/form-error';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/reservations/use-form-options';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/reservations/units/add-ons';

type Unit = App.Data.Reservations.ReservationUnitOptionData;
type ChargeItem = App.Data.Reservations.ChargeItemOptionData;

const NIGHTLY = ['per_night', 'per_guest_night'];

function AddAddOnForm({
    reservationId,
    units,
    chargeItems,
    currency,
    onDone,
}: {
    reservationId: number;
    units: Unit[];
    chargeItems: ChargeItem[];
    currency: App.Enums.Currency | null;
    onDone: () => void;
}) {
    const form = useForm({
        unit_id: String(units[0]?.id ?? ''),
        charge_item_id: '',
        quantity: '1',
        service_date: '',
        unit_amount: '',
    });
    const { data, errors } = form;
    const item = chargeItems.find(
        (one) => String(one.id) === data.charge_item_id,
    );
    const unit = units.find((one) => String(one.id) === data.unit_id);
    const isNightly = item !== undefined && NIGHTLY.includes(item.chargingUnit);
    const showAmount =
        item !== undefined &&
        (item.allowOverride || item.defaultAmount === null);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            charge_item_id: values.charge_item_id,
            quantity: item?.chargingUnit === 'per_item' ? values.quantity : '',
            service_date: isNightly ? '' : values.service_date,
            unit_amount: showAmount ? values.unit_amount : '',
        }));
        form.post(
            store.url({
                reservation: reservationId,
                unit: Number(data.unit_id),
            }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
                {units.length > 1 && (
                    <Field>
                        <FieldLabel htmlFor="add-on-unit_id">Unit</FieldLabel>
                        <EnumSelect
                            id="add-on-unit_id"
                            options={units.map((one) => ({
                                value: String(one.id),
                                label: one.label,
                            }))}
                            value={data.unit_id}
                            onValueChange={(value) =>
                                form.setData('unit_id', value)
                            }
                        />
                    </Field>
                )}
                <Field data-invalid={!!errors.charge_item_id}>
                    <FieldLabel htmlFor="add-on-charge_item_id">
                        Charge item
                    </FieldLabel>
                    <EnumSelect
                        id="add-on-charge_item_id"
                        options={chargeItems.map((one) => ({
                            value: String(one.id),
                            label: `${one.name} · ${one.chargingUnitLabel}`,
                        }))}
                        value={data.charge_item_id}
                        onValueChange={(value) => {
                            const next = chargeItems.find(
                                (one) => String(one.id) === value,
                            );

                            form.setData((current) => ({
                                ...current,
                                charge_item_id: value,
                                unit_amount: next?.defaultAmount ?? '',
                            }));
                        }}
                        aria-invalid={!!errors.charge_item_id}
                    />
                    <FieldError>{errors.charge_item_id}</FieldError>
                </Field>
                {item?.chargingUnit === 'per_item' && (
                    <Field data-invalid={!!errors.quantity}>
                        <FieldLabel htmlFor="add-on-quantity">
                            Quantity
                        </FieldLabel>
                        <Input
                            id="add-on-quantity"
                            type="number"
                            min={1}
                            max={999}
                            value={data.quantity}
                            onChange={(event) =>
                                form.setData('quantity', event.target.value)
                            }
                            aria-invalid={!!errors.quantity}
                        />
                        <FieldError>{errors.quantity}</FieldError>
                    </Field>
                )}
                {item !== undefined && !isNightly && (
                    <Field data-invalid={!!errors.service_date}>
                        <FieldLabel htmlFor="add-on-service_date">
                            Service date
                        </FieldLabel>
                        <DatePicker
                            id="add-on-service_date"
                            value={data.service_date}
                            min={unit?.arrival}
                            max={unit?.departure}
                            onChange={(value) =>
                                form.setData('service_date', value)
                            }
                            placeholder="Optional"
                            aria-invalid={!!errors.service_date}
                        />
                        <FieldDescription>
                            Leave empty to charge it at check-in.
                        </FieldDescription>
                        <FieldError>{errors.service_date}</FieldError>
                    </Field>
                )}
                {isNightly && (
                    <p className="text-sm text-muted-foreground">
                        This item is charged for each night of the stay.
                    </p>
                )}
                {showAmount && (
                    <Field data-invalid={!!errors.unit_amount}>
                        <FieldLabel htmlFor="add-on-unit_amount">
                            Unit amount
                        </FieldLabel>
                        <MoneyInput
                            id="add-on-unit_amount"
                            value={data.unit_amount}
                            onChange={(value) =>
                                form.setData('unit_amount', value)
                            }
                            currency={currency}
                            invalid={!!errors.unit_amount}
                        />
                        {item.defaultAmount !== null && (
                            <FieldDescription>
                                The catalogue price is {item.defaultAmount}.
                            </FieldDescription>
                        )}
                        <FieldError>{errors.unit_amount}</FieldError>
                    </Field>
                )}
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing || item === undefined}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Add add-on
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Adds a charge item to a unit. The fields change with the item's charging unit.
 */
export function AddAddOnDialog({
    open,
    onOpenChange,
    reservationId,
    units,
    chargeItems,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    units: Unit[];
    chargeItems?: ChargeItem[];
    currency: App.Enums.Currency | null;
}) {
    useFormOptions(open, chargeItems !== undefined, 'chargeItems');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add add-on</DialogTitle>
                    <DialogDescription>
                        An extra sold with a unit, such as breakfast or an
                        airport transfer.
                    </DialogDescription>
                </DialogHeader>
                {chargeItems === undefined ? (
                    <div className="flex justify-center py-6">
                        <Spinner />
                    </div>
                ) : (
                    <AddAddOnForm
                        reservationId={reservationId}
                        units={units}
                        chargeItems={chargeItems}
                        currency={currency}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
