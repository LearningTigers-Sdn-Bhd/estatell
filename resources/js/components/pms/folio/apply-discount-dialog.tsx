import { useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormError } from '@/components/pms/form-error';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    quote as quoteRoute,
    store,
} from '@/routes/reservations/folios/discounts';

type Discount = App.Data.Folio.FolioDiscountOptionData;
type Answer = App.Data.Folio.FolioDiscountQuoteResponseData;

function describe(discount: Discount): string {
    const value =
        discount.type === 'percent'
            ? `${Number(discount.value)}%`
            : discount.value;

    return `${discount.name} · ${discount.typeLabel}${value ? ` ${value}` : ''} · ${discount.scopeLabel}`;
}

function QuoteReview({
    answer,
    currency,
}: {
    answer: Answer;
    currency: App.Enums.Currency | null;
}) {
    const { quote, error } = answer;

    return (
        <>
            {error && <FormError errors={{ form: error }} />}
            {quote.lines.length > 0 && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Charge</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead className="text-right">
                                Discount
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {quote.lines.map((line) => (
                            <TableRow key={line.folioLineId}>
                                <TableCell>{line.description}</TableCell>
                                <TableCell className="text-right">
                                    <MoneyText
                                        amount={line.netAmount}
                                        currency={currency}
                                    />
                                </TableCell>
                                <TableCell className="text-right">
                                    {line.share !== null && (
                                        <MoneyText
                                            amount={`-${line.share}`}
                                            currency={currency}
                                        />
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            {quote.amount !== null && !error && (
                <p className="text-sm">
                    Total discount{' '}
                    <strong>
                        <MoneyText amount={quote.amount} currency={currency} />
                    </strong>
                    . Tax on the charges goes down with it.
                </p>
            )}
        </>
    );
}

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folioId: number;
    discounts?: Discount[];
    currency: App.Enums.Currency | null;
};

function ApplyDiscountForm({
    open,
    onOpenChange,
    reservationId,
    folioId,
    discounts = [],
    currency,
    loading,
}: Props & { loading: boolean }) {
    useFormOptions(open, !loading, 'folioDiscounts');

    const form = useForm({ discount_id: '', amount: '' });
    const http = useHttp<{ discount_id: string; amount: string }, Answer>(
        'post',
        quoteRoute.url({ reservation: reservationId, folio: folioId }),
        { discount_id: '', amount: '' },
    );
    const [answer, setAnswer] = useState<Answer | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const discount = discounts.find(
        (one) => String(one.id) === form.data.discount_id,
    );
    const { errors } = form;
    const payload = () => ({
        discount_id: form.data.discount_id,
        amount: discount?.canEnterAmount ? form.data.amount : '',
    });

    const review = () => {
        setProblem(null);
        http.transform(payload);
        http.submit()
            .then((response) => setAnswer(response))
            .catch(() => setProblem('The quote could not be worked out.'));
    };

    const apply = () => {
        form.transform(() => ({
            ...payload(),
            fingerprint: answer?.quote.fingerprint ?? '',
        }));
        form.post(store.url({ reservation: reservationId, folio: folioId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    const reviewing = answer !== null;
    const error = answer?.error ?? null;
    const noneAvailable = !loading && discounts.length === 0;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Apply discount"
            description="The discount is posted under each charge it covers, and the tax on those charges goes down with it."
            errors={errors}
            onSubmit={reviewing ? apply : review}
            submitLabel="Review discount"
            processing={http.processing}
            submitDisabled={
                discount === undefined ||
                (discount.canEnterAmount && form.data.amount === '')
            }
            loading={loading}
            footer={
                noneAvailable ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                ) : reviewing ? (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setAnswer(null)}
                        >
                            Back
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !!error}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Apply discount
                        </Button>
                    </>
                ) : undefined
            }
        >
            {noneAvailable ? (
                <p className="text-sm text-muted-foreground">
                    No discount is available for you.
                </p>
            ) : answer !== null ? (
                <>
                    <QuoteReview answer={answer} currency={currency} />
                </>
            ) : (
                <>
                    <FormError errors={{ form: problem ?? undefined }} />
                    <Field data-invalid={!!errors.discount_id}>
                        <FieldLabel htmlFor="discount-discount_id">
                            Discount
                        </FieldLabel>
                        <EnumSelect
                            id="discount-discount_id"
                            options={discounts.map((one) => ({
                                value: String(one.id),
                                label: describe(one),
                            }))}
                            placeholder="Choose a discount"
                            value={form.data.discount_id}
                            onValueChange={(value) => {
                                const next = discounts.find(
                                    (one) => String(one.id) === value,
                                );

                                form.setData((current) => ({
                                    ...current,
                                    discount_id: value,
                                    amount:
                                        next?.type === 'fixed'
                                            ? (next.value ?? '')
                                            : '',
                                }));
                            }}
                            aria-invalid={!!errors.discount_id}
                        />
                        <FieldError>{errors.discount_id}</FieldError>
                    </Field>
                    {discount?.canEnterAmount && (
                        <Field data-invalid={!!errors.amount}>
                            <FieldLabel htmlFor="discount-amount">
                                Amount
                            </FieldLabel>
                            <MoneyInput
                                id="discount-amount"
                                value={form.data.amount}
                                onChange={(value) =>
                                    form.setData('amount', value)
                                }
                                currency={currency}
                                invalid={!!errors.amount}
                            />
                            <FieldDescription>
                                It is shared across the charges in proportion to
                                their amounts.
                            </FieldDescription>
                            <FieldError>{errors.amount}</FieldError>
                        </Field>
                    )}
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Applies a discount in two steps: pick it (and the amount when the user may enter one), review the quote from the
 * server, then apply it with the quote's fingerprint. A folio that changed in between is refused.
 */
export function ApplyDiscountDialog(props: Props) {
    return (
        <ApplyDiscountForm
            key={useFreshOnOpen(props.open)}
            {...props}
            loading={props.discounts === undefined}
        />
    );
}
