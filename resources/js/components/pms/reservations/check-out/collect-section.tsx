import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { SummaryRow } from './summary-row';
import type { CheckOutState } from './use-check-out-form';

type Options = App.Data.Payments.PaymentFormOptionsData;

/**
 * Collect balance (the amount, the method and the reference) and the lines that sum what the check-out collects and
 * refunds, with what remains. Renders nothing when there is nothing to collect or refund.
 */
export function CollectSection({
    state,
    paymentOptions,
    currency,
}: {
    state: CheckOutState;
    paymentOptions: Options | undefined;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const { form, preview, figures, methods, methodId, method } = state;
    const { data, errors } = form;
    const {
        collecting,
        collectAmount,
        refunding,
        refund,
        damageOnFolio,
        remaining,
        settled,
    } = figures;

    if (preview === null) {
        return null;
    }

    return (
        <>
            {collecting && (
                <div className="flex flex-col gap-4">
                    <div className="flex justify-between gap-4 text-sm font-medium">
                        Collect balance
                        <MoneyText
                            amount={collectAmount.toFixed(2)}
                            currency={currency}
                        />
                    </div>
                    <div
                        className={cn(
                            'grid gap-4',
                            method?.requiresReference && 'sm:grid-cols-2',
                        )}
                    >
                        <Field data-invalid={!!errors.payment_method_id}>
                            <FieldLabel htmlFor="check-out-payment_method_id">
                                Method
                            </FieldLabel>
                            {paymentOptions === undefined ? (
                                <Skeleton className="h-9 w-full" />
                            ) : (
                                <EnumSelect
                                    id="check-out-payment_method_id"
                                    options={methods.map((one) => ({
                                        value: String(one.id),
                                        label: one.name,
                                    }))}
                                    value={methodId}
                                    onValueChange={(value) =>
                                        form.setData('payment_method_id', value)
                                    }
                                    aria-invalid={!!errors.payment_method_id}
                                />
                            )}
                            <FieldError>{errors.payment_method_id}</FieldError>
                        </Field>
                        {method?.requiresReference && (
                            <Field data-invalid={!!errors.reference}>
                                <FieldLabel htmlFor="check-out-reference">
                                    Reference
                                </FieldLabel>
                                <Input
                                    id="check-out-reference"
                                    value={data.reference}
                                    onChange={(event) =>
                                        form.setData(
                                            'reference',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={100}
                                    autoComplete="off"
                                    aria-invalid={!!errors.reference}
                                />
                                <FieldError>{errors.reference}</FieldError>
                            </Field>
                        )}
                    </div>
                </div>
            )}
            {(collecting || (refunding && refund > 0) || damageOnFolio > 0) && (
                <>
                    <Separator />
                    <div className="flex flex-col gap-1 text-sm">
                        {damageOnFolio > 0 && (
                            <SummaryRow
                                label="Damage"
                                amount={damageOnFolio}
                                currency={currency}
                            />
                        )}
                        {collecting && (
                            <SummaryRow
                                label={`Collect${method ? ` by ${method.name}` : ''}`}
                                amount={collectAmount}
                                currency={currency}
                            />
                        )}
                        {refunding && refund > 0 && (
                            <SummaryRow
                                label={`Refund${preview.depositMethod ? ` by ${preview.depositMethod}` : ''}`}
                                amount={refund}
                                currency={currency}
                                negative
                            />
                        )}
                        <div className="flex justify-between gap-4 font-medium">
                            <span>Remaining after check-out</span>
                            <span
                                className={cn(!settled && 'text-destructive')}
                            >
                                <MoneyText
                                    amount={remaining.toFixed(2)}
                                    currency={currency}
                                />
                            </span>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}
