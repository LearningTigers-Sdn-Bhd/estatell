import { useForm, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { FormError } from '@/components/pms/form-error';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store as storeOnFolio } from '@/routes/reservations/folios/payments';
import { quote as quoteRoute } from '@/routes/reservations/payments';
import { store as storeOnUnit } from '@/routes/reservations/units/payments';

/** The fields of a folio the Dialog reads, so a page that has only these (the group check-out) can open it. */
type Folio = Pick<
    App.Data.Folio.FolioData,
    | 'key'
    | 'name'
    | 'balance'
    | 'paymentUnitId'
    | 'paymentPurposes'
    | 'defaultPaymentPurpose'
>;
type Options = App.Data.Payments.PaymentFormOptionsData;
type Quote = App.Data.Payments.PaymentQuoteData;

function QuoteLine({
    label,
    amount,
    currency,
    strong = false,
}: {
    label: string;
    amount: string;
    currency: App.Data.Property.CurrencyData | null;
    strong?: boolean;
}) {
    return (
        <div className="flex justify-between gap-4">
            <dt className={strong ? 'font-medium' : 'text-muted-foreground'}>
                {label}
            </dt>
            <dd className={strong ? 'font-medium' : undefined}>
                <MoneyText amount={amount} currency={currency} />
            </dd>
        </div>
    );
}

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: Folio;
    options?: Options;
    currency: App.Data.Property.CurrencyData | null;
};

function RecordPaymentForm({
    open,
    onOpenChange,
    reservationId,
    folio,
    options = { methods: [], businessDate: null },
    currency,
    loading,
}: Props & { loading: boolean }) {
    useFormOptions(open, !loading, 'paymentOptions');

    const [key] = useState(() => crypto.randomUUID());
    const form = useForm({
        payment_method_id: String(options.methods[0]?.id ?? ''),
        purpose: folio.defaultPaymentPurpose ?? '',
        amount:
            folio.balance !== null && Number(folio.balance) > 0
                ? folio.balance
                : '',
        reference: '',
        service_date: options.businessDate ?? '',
    });
    const { data, errors } = form;
    const method = options.methods.find(
        (one) => String(one.id) === data.payment_method_id,
    );
    const oldSystem = method?.isOldSystem === true;
    const needsReference = method?.requiresReference === true || oldSystem;
    const http = useHttp<{ payment_method_id: string; amount: string }, Quote>(
        'post',
        quoteRoute.url({ reservation: reservationId }),
        { payment_method_id: '', amount: '' },
    );
    const [quote, setQuote] = useState<Quote | null>(null);

    useEffect(() => {
        if (data.payment_method_id === '' || data.amount === '') {
            setQuote(null);

            return;
        }

        let current = true;
        const timer = window.setTimeout(() => {
            http.transform(() => ({
                payment_method_id: data.payment_method_id,
                amount: data.amount,
            }));
            http.submit()
                .then((answer) => current && setQuote(answer))
                .catch(() => current && setQuote(null));
        }, 300);

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
        // The quote depends only on the method and the amount.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.payment_method_id, data.amount]);

    const submit = () => {
        form.transform((values) => ({
            ...values,
            reference: needsReference ? values.reference : '',
            service_date: oldSystem ? values.service_date : '',
            idempotency_key: key,
        }));
        form.post(
            folio.paymentUnitId !== null
                ? storeOnUnit.url({
                      reservation: reservationId,
                      unit: folio.paymentUnitId,
                  })
                : storeOnFolio.url({
                      reservation: reservationId,
                      folio: Number(folio.key),
                  }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    const ready = quote !== null && quote.error === null && quote.total;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Record payment"
            description={`Money received for ${folio.name}. A method surcharge is added to the total and posted as a charge.`}
            errors={errors}
            onSubmit={submit}
            submitLabel={
                <>
                    Record
                    {ready && (
                        <MoneyText
                            amount={quote.total ?? '0.00'}
                            currency={currency}
                        />
                    )}
                </>
            }
            processing={form.processing}
            submitDisabled={!ready}
            loading={loading}
        >
            <Field data-invalid={!!errors.payment_method_id}>
                <FieldLabel htmlFor="payment-method">Method</FieldLabel>
                <EnumSelect
                    id="payment-method"
                    options={options.methods.map((one) => ({
                        value: String(one.id),
                        label: one.name,
                    }))}
                    value={data.payment_method_id}
                    onValueChange={(value) =>
                        form.setData('payment_method_id', value)
                    }
                    aria-invalid={!!errors.payment_method_id}
                />
                <FieldError>{errors.payment_method_id}</FieldError>
            </Field>
            {folio.paymentPurposes.length > 1 && (
                <Field data-invalid={!!errors.purpose}>
                    <FieldLabel htmlFor="payment-purpose">Purpose</FieldLabel>
                    <EnumSelect
                        id="payment-purpose"
                        options={folio.paymentPurposes}
                        value={data.purpose}
                        onValueChange={(value) =>
                            form.setData('purpose', value)
                        }
                        aria-invalid={!!errors.purpose}
                    />
                    <FieldError>{errors.purpose}</FieldError>
                </Field>
            )}
            <Field data-invalid={!!errors.amount}>
                <FieldLabel htmlFor="payment-amount">Amount</FieldLabel>
                <MoneyInput
                    id="payment-amount"
                    value={data.amount}
                    onChange={(value) => form.setData('amount', value)}
                    currency={currency}
                    invalid={!!errors.amount}
                />
                <FieldError>{errors.amount}</FieldError>
            </Field>
            {oldSystem && (
                <Field data-invalid={!!errors.service_date}>
                    <FieldLabel htmlFor="payment-service-date">
                        Date paid in the old system
                    </FieldLabel>
                    <DatePicker
                        id="payment-service-date"
                        variant="dropdown"
                        value={data.service_date}
                        onChange={(value) =>
                            form.setData('service_date', value)
                        }
                        max={options.businessDate ?? undefined}
                        aria-invalid={!!errors.service_date}
                    />
                    <FieldError>{errors.service_date}</FieldError>
                </Field>
            )}
            {needsReference && (
                <Field data-invalid={!!errors.reference}>
                    <FieldLabel htmlFor="payment-reference">
                        {oldSystem ? 'Old receipt number' : 'Reference'}
                    </FieldLabel>
                    <Input
                        id="payment-reference"
                        maxLength={100}
                        placeholder={
                            oldSystem ? 'e.g. OR-1203' : 'e.g. Visa ending 4242'
                        }
                        value={data.reference}
                        onChange={(event) =>
                            form.setData('reference', event.target.value)
                        }
                        aria-invalid={!!errors.reference}
                    />
                    <FieldError>{errors.reference}</FieldError>
                </Field>
            )}
            {oldSystem && (
                <p className="text-sm text-muted-foreground">
                    An advance tied to a booking goes on its folio. Money not
                    tied to a booking goes on the company.
                </p>
            )}
            {quote?.error && <FormError errors={{ form: quote.error }} />}
            {ready && (
                <dl className="flex flex-col gap-1 rounded-lg bg-muted px-3 py-2 text-sm">
                    {Number(quote.surcharge) > 0 && (
                        <>
                            <QuoteLine
                                label="Surcharge"
                                amount={quote.surcharge ?? '0.00'}
                                currency={currency}
                            />
                            <QuoteLine
                                label="Tax on surcharge"
                                amount={quote.surchargeTax ?? '0.00'}
                                currency={currency}
                            />
                        </>
                    )}
                    <QuoteLine
                        label="Total to collect"
                        amount={quote.total ?? '0.00'}
                        currency={currency}
                        strong
                    />
                </dl>
            )}
        </FormOverlay>
    );
}

/**
 * Records a payment on an Open folio, or on a "not started" entry, which creates the unit's folio. The quote shows
 * the method's surcharge, its tax and the total to collect before the user confirms. A refusal shows in the Dialog.
 */
export function RecordPaymentDialog(props: Props) {
    return (
        <RecordPaymentForm
            key={useFreshOnOpen(props.open)}
            {...props}
            loading={props.options === undefined}
        />
    );
}
