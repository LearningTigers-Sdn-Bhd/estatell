import { Info, Pencil, TriangleAlert, X } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { useDayUseCheckOut } from '@/components/pms/reservations/use-day-use-check-out';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: App.Data.Reservations.ReservationUnitData;
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    currency: App.Data.Property.CurrencyData | null;
};

function DayUseCheckOutForm(props: Props) {
    const { unit, currency, open, onOpenChange } = props;
    const state = useDayUseCheckOut({ ...props, unitId: unit.id });
    const { form, preview, settlement, errors } = state;
    const { data } = form;
    const manual =
        state.overriding ||
        (preview?.hours != null && preview.planPrice === null);
    const time = (value: string | null | undefined) =>
        value?.slice(0, 5) ?? '—';

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`Check out as day use · ${unit.assigned.length > 0 ? `Room ${unit.assigned.join(', ')}` : unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    {preview?.checkedInTime &&
                        `Checked in ${preview.checkedInTime} today · `}
                    Booked <DateText date={unit.arrival} /> →{' '}
                    <DateText date={unit.departure} />
                </>
            }
            errors={errors}
            onSubmit={state.save}
            submitLabel="Check out as day use"
            processing={form.processing}
            submitDisabled={!state.canSave}
            loading={preview === null && !errors.form}
        >
            <div className="grid grid-cols-3 gap-3">
                {(['start', 'end'] as const).map((field) => (
                    <Field key={field} data-invalid={!!errors[field]}>
                        <FieldLabel htmlFor={`day-use-${field}`}>
                            {field === 'start' ? 'Start' : 'End'}
                        </FieldLabel>
                        <Input
                            id={`day-use-${field}`}
                            name={field}
                            type="time"
                            step="1"
                            value={data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.value)
                            }
                            aria-invalid={!!errors[field]}
                        />
                        <FieldError>{errors[field]}</FieldError>
                    </Field>
                ))}
                <Field>
                    <FieldLabel>Hours</FieldLabel>
                    <output
                        className="py-2 text-sm tabular-nums"
                        aria-live="polite"
                    >
                        {state.current ? (preview?.hours ?? '—') : '…'}
                    </output>
                </Field>
            </div>
            {preview?.midnightLimited && (
                <p className="text-sm text-muted-foreground">
                    Ends at {time(preview.blockEnd)}: a day use must end before
                    midnight. The last part hour is not charged.
                </p>
            )}
            {preview?.ratePlanName && (
                <p className="text-sm">
                    <span className="text-muted-foreground">Plan · </span>
                    {preview.ratePlanName}
                </p>
            )}
            {manual ? (
                <FieldGroup>
                    <Field data-invalid={!!errors.amount}>
                        <FieldLabel htmlFor="day-use-amount">
                            {preview?.planPrice != null ? 'Price' : 'Amount'}
                        </FieldLabel>
                        <div className="flex items-center gap-2">
                            <MoneyInput
                                id="day-use-amount"
                                value={data.amount}
                                onChange={(value) =>
                                    form.setData('amount', value)
                                }
                                currency={currency}
                                invalid={!!errors.amount}
                            />
                            {preview?.planPrice != null && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Use plan price"
                                    onClick={() => {
                                        state.setOverriding(false);
                                        form.setData((values) => ({
                                            ...values,
                                            amount: '',
                                            reason: '',
                                        }));
                                        form.clearErrors('amount', 'reason');
                                    }}
                                >
                                    <X />
                                </Button>
                            )}
                        </div>
                        <FieldDescription>
                            {preview?.planPrice != null ? (
                                <>
                                    Plan price{' '}
                                    <MoneyText
                                        amount={preview.planPrice}
                                        currency={currency}
                                    />
                                </>
                            ) : (
                                '0 allowed.'
                            )}
                        </FieldDescription>
                        <FieldError>{errors.amount}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="day-use-reason">
                            {preview?.planPrice != null
                                ? 'Override reason'
                                : 'Reason'}
                        </FieldLabel>
                        <Input
                            id="day-use-reason"
                            name="reason"
                            value={data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={500}
                            required
                            aria-invalid={!!errors.reason}
                            placeholder="e.g. Guest left the same day"
                        />
                        <FieldError>{errors.reason}</FieldError>
                    </Field>
                </FieldGroup>
            ) : preview?.planPrice != null ? (
                <div className="flex items-center gap-2 text-sm">
                    <span>Price</span>
                    <MoneyText amount={preview.planPrice} currency={currency} />
                    <span className="text-muted-foreground">
                        ({preview.hours} hours, {unit.roomType})
                    </span>
                    <Tooltip>
                        <TooltipTrigger
                            render={
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Day use price details"
                                />
                            }
                        >
                            <Info />
                        </TooltipTrigger>
                        <TooltipContent>
                            <div className="flex flex-col gap-1">
                                <span>
                                    Day use · {time(preview.start)}–
                                    {time(preview.blockEnd)}
                                </span>
                                {preview.lines.map((line, index) => (
                                    <div
                                        key={index}
                                        className="flex justify-between gap-4"
                                    >
                                        <span>{line.description}</span>
                                        <MoneyText
                                            amount={line.amount}
                                            currency={currency}
                                        />
                                    </div>
                                ))}
                            </div>
                        </TooltipContent>
                    </Tooltip>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Override day use price"
                        onClick={() => {
                            state.setOverriding(true);
                            form.setData('amount', preview.planPrice ?? '');
                        }}
                    >
                        <Pencil />
                    </Button>
                </div>
            ) : null}
            {preview?.noTaxes && (
                <Alert>
                    <TriangleAlert />
                    <AlertDescription>
                        DAY_USE has no taxes set. Set them under Room revenue.
                    </AlertDescription>
                </Alert>
            )}
            {preview !== null && preview.droppedAddOns.length > 0 && (
                <Alert>
                    <Info />
                    <AlertDescription>
                        Removed at check-out (not charged yet):{' '}
                        {preview.droppedAddOns.join(', ')}.
                    </AlertDescription>
                </Alert>
            )}
            {settlement?.error && (
                <Alert variant="destructive">
                    <AlertDescription>{settlement.error}</AlertDescription>
                </Alert>
            )}
            {settlement !== null && preview !== null && (
                <>
                    <section
                        className="flex flex-col gap-2 rounded-lg border p-3"
                        aria-label="Folio after check-out"
                        aria-busy={!state.current}
                    >
                        <h3 className="text-sm font-medium">
                            Folio after check-out
                        </h3>
                        {preview.folioLines.map((line, index) => (
                            <div
                                key={index}
                                className="flex justify-between gap-4 text-sm"
                            >
                                <span
                                    className={
                                        line.level > 0
                                            ? 'pl-3 text-muted-foreground'
                                            : ''
                                    }
                                >
                                    {line.description}
                                </span>
                                <MoneyText
                                    amount={
                                        Number(settlement.refundDefault) > 0 &&
                                        index === preview.folioLines.length - 1
                                            ? state.refund
                                            : line.amount
                                    }
                                    currency={currency}
                                />
                            </div>
                        ))}
                    </section>
                    {Number(settlement.refundDefault) > 0 &&
                        settlement.canRefund && (
                            <Field data-invalid={!!errors.refund_amount}>
                                <FieldLabel htmlFor="day-use-refund">
                                    Refund
                                </FieldLabel>
                                <MoneyInput
                                    id="day-use-refund"
                                    value={state.refund}
                                    onChange={(value) =>
                                        form.setData('refund_amount', value)
                                    }
                                    currency={currency}
                                    invalid={!!errors.refund_amount}
                                />
                                <FieldDescription>
                                    Method ·{' '}
                                    {settlement.refundParts
                                        .map(
                                            (part) =>
                                                `${part.method}${part.reference ? ` ref ${part.reference}` : ''}`,
                                        )
                                        .join(', ') || settlement.depositMethod}
                                </FieldDescription>
                                {settlement.refundParts.length > 1 &&
                                    settlement.refundParts.map(
                                        (part, index) => (
                                            <div
                                                key={index}
                                                className="flex justify-between gap-4 text-sm text-muted-foreground"
                                            >
                                                <span>
                                                    {part.purpose} ·{' '}
                                                    {part.method}
                                                </span>
                                                <MoneyText
                                                    amount={part.amount}
                                                    currency={currency}
                                                />
                                            </div>
                                        ),
                                    )}
                                <FieldError>{errors.refund_amount}</FieldError>
                            </Field>
                        )}
                    {state.collecting && (
                        <>
                            <Separator />
                            <div className="flex justify-between gap-4 text-sm font-medium">
                                <span>Collect balance</span>
                                <MoneyText
                                    amount={state.owing}
                                    currency={currency}
                                />
                            </div>
                            <Field data-invalid={!!errors.payment_method_id}>
                                <FieldLabel htmlFor="day-use-method">
                                    Method
                                </FieldLabel>
                                <EnumSelect
                                    id="day-use-method"
                                    name="payment_method_id"
                                    options={state.methods.map((method) => ({
                                        value: String(method.id),
                                        label: method.name,
                                    }))}
                                    value={state.methodId}
                                    onValueChange={(value) =>
                                        form.setData('payment_method_id', value)
                                    }
                                    aria-invalid={!!errors.payment_method_id}
                                />
                                <FieldError>
                                    {errors.payment_method_id}
                                </FieldError>
                            </Field>
                            {state.method?.requiresReference && (
                                <Field data-invalid={!!errors.reference}>
                                    <FieldLabel htmlFor="day-use-reference">
                                        Reference
                                    </FieldLabel>
                                    <Input
                                        id="day-use-reference"
                                        name="reference"
                                        value={data.reference}
                                        onChange={(event) =>
                                            form.setData(
                                                'reference',
                                                event.target.value,
                                            )
                                        }
                                        maxLength={100}
                                        aria-invalid={!!errors.reference}
                                    />
                                    <FieldError>{errors.reference}</FieldError>
                                </Field>
                            )}
                        </>
                    )}
                    {Number(settlement.owing) > 0 && !settlement.canCollect && (
                        <p className="text-sm text-muted-foreground">
                            Collect the balance with Record payment on the
                            folio, then check out.
                        </p>
                    )}
                    {Number(settlement.balance) < 0 &&
                        !settlement.canRefund && (
                            <p className="text-sm text-muted-foreground">
                                A refund is due on this folio. Ask someone who
                                may refund payments to give it back first.
                            </p>
                        )}
                    {!state.canSave &&
                        state.current &&
                        preview.refusal === null && (
                            <p className="text-sm text-muted-foreground">
                                The folio is not at 0 yet, so check-out is not
                                open.
                            </p>
                        )}
                </>
            )}
        </FormOverlay>
    );
}

export function DayUseCheckOutDialog(props: Props) {
    const fresh = useFreshOnOpen(props.open);

    return <DayUseCheckOutForm key={`${props.unit.id}-${fresh}`} {...props} />;
}
