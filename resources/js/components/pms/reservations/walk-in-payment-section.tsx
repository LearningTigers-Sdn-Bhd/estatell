import { useEffect } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { paymentTotal } from '@/components/pms/reservations/walk-in-payment';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

type Payment = App.Data.Reservations.WalkInPaymentData;
type Options = App.Data.Payments.PaymentFormOptionsData;
type Currency = App.Data.Property.CurrencyData | null;

export type WalkInPaymentValues = {
    collect_room_charges: boolean;
    collect_deposit: boolean;
    payment_method_id: string;
    reference: string;
    expected_total: string;
};

function AmountRow({
    label,
    amount,
    currency,
    exempt = false,
    muted = false,
}: {
    label: string;
    amount: string;
    currency: Currency;
    exempt?: boolean;
    muted?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex justify-between gap-3 text-sm',
                muted && 'text-muted-foreground',
            )}
        >
            <span>{label}</span>
            <span>
                {exempt ? (
                    'Exempt'
                ) : (
                    <MoneyText amount={amount} currency={currency} />
                )}
            </span>
        </div>
    );
}

/**
 * The Payment section at the bottom of the walk-in summary rail. It shows what the server answered for the whole form
 * (see `useWalkInPayment`) and adds nothing up: the total is one of the server's amounts for the ticked boxes, and it
 * is posted as `expected_total`. The boxes, the method and the reference are form fields.
 */
export function WalkInPaymentSection({
    payment,
    loading,
    invalid,
    options,
    currency,
    values,
    errors,
    onChange,
    onDerive,
}: {
    payment: Payment | null;
    loading: boolean;
    invalid: boolean;
    options: Options | null | undefined;
    currency: Currency;
    values: WalkInPaymentValues;
    errors: Partial<Record<string, string>>;
    onChange: (patch: Partial<WalkInPaymentValues>) => void;
    /** Sets a value the server worked out or the first method, which is not an edit by the user: the form stays clean. */
    onDerive: (patch: Partial<WalkInPaymentValues>) => void;
}) {
    const methods = (options?.methods ?? []).filter((one) => !one.isOldSystem);
    const methodId = values.payment_method_id || String(methods[0]?.id ?? '');
    const method = methods.find((one) => String(one.id) === methodId);
    const roomsHidden = payment?.roomChargesHidden === true;
    const roomsTicked = values.collect_room_charges && !roomsHidden;
    const total =
        payment === null
            ? ''
            : paymentTotal(
                  payment,
                  values.collect_room_charges,
                  values.collect_deposit,
              );

    useEffect(() => {
        if (values.payment_method_id === '' && methodId !== '') {
            onDerive({ payment_method_id: methodId });
        }
    }, [methodId, values.payment_method_id]);

    useEffect(() => {
        if (values.expected_total !== total) {
            onDerive({ expected_total: total });
        }
    }, [total]);

    return (
        <div className="flex flex-col gap-2" data-slot="walk-in-payment">
            <span className="text-sm font-medium">Payment</span>
            {invalid ? (
                <p className="text-sm text-muted-foreground">
                    Fix the booking to see the payment
                </p>
            ) : payment === null ? (
                loading ? (
                    <div className="flex flex-col gap-2">
                        <Skeleton className="h-5 w-full" />
                        <Skeleton className="h-5 w-full" />
                        <Skeleton className="h-5 w-2/3" />
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Add the rooms and the booker to see the payment.
                    </p>
                )
            ) : (
                <div
                    className={cn(
                        'flex flex-col gap-2',
                        loading && 'opacity-60',
                    )}
                >
                    {roomsHidden ? (
                        <p className="text-sm text-muted-foreground">
                            The company is billed for the room charges.
                        </p>
                    ) : (
                        <>
                            <FieldLabel htmlFor="walk-in-collect_room_charges">
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="walk-in-collect_room_charges"
                                        checked={values.collect_room_charges}
                                        onCheckedChange={(checked) =>
                                            onChange({
                                                collect_room_charges: checked,
                                            })
                                        }
                                    />
                                    <FieldContent>
                                        <span className="flex justify-between gap-3 text-sm font-medium">
                                            Room charges and tax
                                            <MoneyText
                                                amount={payment.roomCharges}
                                                currency={currency}
                                            />
                                        </span>
                                    </FieldContent>
                                </Field>
                            </FieldLabel>
                            {!roomsTicked &&
                                payment.taxes.map((tax) => (
                                    <AmountRow
                                        key={tax.name}
                                        label={tax.name}
                                        amount={tax.amount}
                                        exempt={tax.exempt}
                                        currency={currency}
                                        muted
                                    />
                                ))}
                        </>
                    )}
                    {payment.deposit !== null && (
                        <FieldLabel htmlFor="walk-in-collect_deposit">
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="walk-in-collect_deposit"
                                    checked={values.collect_deposit}
                                    onCheckedChange={(checked) =>
                                        onChange({ collect_deposit: checked })
                                    }
                                />
                                <FieldContent>
                                    <span className="flex justify-between gap-3 text-sm font-medium">
                                        <span>
                                            Security deposit{' '}
                                            <span className="font-normal text-muted-foreground">
                                                <MoneyText
                                                    amount={
                                                        payment.deposit.amount
                                                    }
                                                    currency={currency}
                                                    plain
                                                />{' '}
                                                × {payment.deposit.units}
                                            </span>
                                        </span>
                                        <MoneyText
                                            amount={payment.deposit.total}
                                            currency={currency}
                                        />
                                    </span>
                                </FieldContent>
                            </Field>
                        </FieldLabel>
                    )}
                    <div className="flex justify-between gap-3 text-sm font-medium">
                        <span>Total</span>
                        <MoneyText amount={total} currency={currency} />
                    </div>
                    {Number(total) > 0 && (
                        <div
                            className={cn(
                                'grid gap-3',
                                method?.requiresReference && 'sm:grid-cols-2',
                            )}
                        >
                            <Field data-invalid={!!errors.payment_method_id}>
                                <FieldLabel htmlFor="walk-in-payment_method_id">
                                    Method
                                </FieldLabel>
                                {options == null ? (
                                    <Skeleton className="h-9 w-full" />
                                ) : (
                                    <EnumSelect
                                        id="walk-in-payment_method_id"
                                        options={methods.map((one) => ({
                                            value: String(one.id),
                                            label: one.name,
                                        }))}
                                        value={methodId}
                                        onValueChange={(value) =>
                                            onChange({
                                                payment_method_id: value,
                                            })
                                        }
                                        aria-invalid={
                                            !!errors.payment_method_id
                                        }
                                    />
                                )}
                                <FieldError>
                                    {errors.payment_method_id}
                                </FieldError>
                            </Field>
                            {method?.requiresReference && (
                                <Field data-invalid={!!errors.reference}>
                                    <FieldLabel htmlFor="walk-in-reference">
                                        Reference
                                    </FieldLabel>
                                    <Input
                                        id="walk-in-reference"
                                        value={values.reference}
                                        onChange={(event) =>
                                            onChange({
                                                reference: event.target.value,
                                            })
                                        }
                                        placeholder="e.g. 4821 0933"
                                        maxLength={100}
                                        autoComplete="off"
                                        aria-invalid={!!errors.reference}
                                    />
                                    <FieldError>{errors.reference}</FieldError>
                                </Field>
                            )}
                        </div>
                    )}
                    <FieldError>{errors.amount_changed}</FieldError>
                </div>
            )}
        </div>
    );
}
