import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { checkIn, checkInPreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitCheckInPreviewData;
type Options = App.Data.Payments.PaymentFormOptionsData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    paymentOptions?: Options;
    canAssignRoom: boolean;
    onAssignRoom: () => void;
    currency: App.Data.Property.CurrencyData | null;
};

function AmountRow({
    label,
    amount,
    currency,
    exempt = false,
}: {
    label: string;
    amount: string;
    currency: App.Data.Property.CurrencyData | null;
    exempt?: boolean;
}) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
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

function CheckInForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    paymentOptions,
    canAssignRoom,
    onAssignRoom,
    currency,
}: Props) {
    useFormOptions(open, paymentOptions !== undefined, 'paymentOptions');

    const key = { reservation: reservationId, unit: unit.id };
    const { preview } = useUnitPreview<Preview>(checkInPreview.url(key), open);
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const methods = (paymentOptions?.methods ?? []).filter(
        (one) => !one.isOldSystem,
    );
    const form = useForm({
        nationality_code: '',
        without_card: false,
        credit_override_reason: '',
        collect_now: true,
        collect_deposit: true,
        payment_method_id: '',
        reference: '',
    });
    const { data, errors } = form;
    const methodId = data.payment_method_id || String(methods[0]?.id ?? '');
    const method = methods.find((one) => String(one.id) === methodId);
    const collection = preview?.collection ?? null;
    const taxesTotal =
        collection !== null && !collection.taxesHidden
            ? Number(collection.taxesTotal)
            : 0;
    const depositAmount =
        collection?.deposit != null && data.collect_deposit
            ? Number(collection.deposit)
            : 0;
    const collectTotal = taxesTotal + depositAmount;
    // Rows can exist with nothing due (an exempt tax), so Collect now has nothing to take.
    const nothingToCollect =
        collection !== null && taxesTotal === 0 && collection.deposit === null;
    const collecting = collection !== null && !nothingToCollect;
    const needsOverride =
        preview !== null && preview.cardsMissing > 0 && !data.without_card;

    const submit = () => {
        form.transform((values) => ({
            ...values,
            payment_method_id: collecting ? methodId : '',
            reference: method?.requiresReference ? values.reference : '',
            collect_now: collecting && values.collect_now,
            collect_taxes: collecting && values.collect_now,
            collect_deposit:
                collection?.deposit != null &&
                values.collect_now &&
                values.collect_deposit,
            credit_override_reason: preview?.creditExceeded
                ? values.credit_override_reason
                : '',
            idempotency_key: idempotencyKey,
        }));
        form.post(checkIn.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`Check in · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    <DateText date={unit.arrival} /> →{' '}
                    <DateText date={unit.departure} /> ·{' '}
                    {unit.stayLabel ??
                        `${unit.nightCount} ${unit.nightCount === 1 ? 'night' : 'nights'}`}
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Check in"
            processing={form.processing}
            submitDisabled={
                preview === null ||
                preview.error !== null ||
                preview.roomState === 'none' ||
                (data.collect_now && collecting && paymentOptions === undefined)
            }
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    {preview.error !== null && (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    )}
                    <div className="flex flex-col gap-1 text-sm">
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">Room</span>
                            <span>
                                {preview.roomState === 'assigned' ? (
                                    preview.room
                                ) : preview.roomState === 'auto' ? (
                                    <span className="text-muted-foreground">
                                        Assigned when you check in
                                    </span>
                                ) : (
                                    <span className="text-destructive">
                                        Not assigned for tonight
                                    </span>
                                )}
                            </span>
                        </div>
                        {preview.roomState === 'none' && (
                            <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
                                {canAssignRoom
                                    ? 'Assign a room before you check in.'
                                    : 'Ask someone who can assign rooms.'}
                                {canAssignRoom && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={onAssignRoom}
                                    >
                                        Assign a room
                                    </Button>
                                )}
                            </div>
                        )}
                        {preview.cardRequired && (
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Registration card
                                </span>
                                <span
                                    className={
                                        preview.cardsMissing > 0
                                            ? 'text-warning'
                                            : 'text-success'
                                    }
                                >
                                    {preview.cardsMissing === 0
                                        ? 'Signed'
                                        : preview.cardsMissing === 1
                                          ? '1 card not signed'
                                          : `${preview.cardsMissing} cards not signed`}
                                </span>
                            </div>
                        )}
                    </div>
                    {preview.cardsMissing > 0 &&
                        preview.canCheckInWithoutCard && (
                            <FieldLabel htmlFor="check-in-without_card">
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="check-in-without_card"
                                        checked={data.without_card}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'without_card',
                                                checked,
                                            )
                                        }
                                    />
                                    <FieldContent>
                                        <span className="text-sm font-medium">
                                            Check in without a card
                                        </span>
                                        <FieldDescription>
                                            The card stays unsigned. This is
                                            logged with your name.
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            </FieldLabel>
                        )}
                    {needsOverride && !preview.canCheckInWithoutCard && (
                        <p className="text-sm text-muted-foreground">
                            Sign the registration card first, or ask someone who
                            may check in without one.
                        </p>
                    )}
                    {preview.needsNationality && (
                        <Field data-invalid={!!errors.nationality_code}>
                            <FieldLabel htmlFor="check-in-nationality_code">
                                Nationality
                            </FieldLabel>
                            <CountrySelect
                                id="check-in-nationality_code"
                                name="nationality_code"
                                value={data.nationality_code}
                                onValueChange={(value) =>
                                    form.setData('nationality_code', value)
                                }
                                options={preview.countries}
                                invalid={!!errors.nationality_code}
                            />
                            <FieldDescription>
                                A tax depends on it. It is saved to the guest
                                profile.
                            </FieldDescription>
                            <FieldError>{errors.nationality_code}</FieldError>
                        </Field>
                    )}
                    {preview.creditExceeded &&
                        (preview.canOverrideCredit ? (
                            <Field
                                data-invalid={!!errors.credit_override_reason}
                            >
                                <FieldLabel htmlFor="check-in-credit_override_reason">
                                    Credit limit override reason
                                </FieldLabel>
                                <Textarea
                                    id="check-in-credit_override_reason"
                                    value={data.credit_override_reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'credit_override_reason',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={500}
                                    rows={2}
                                    aria-invalid={
                                        !!errors.credit_override_reason
                                    }
                                />
                                <FieldDescription>
                                    This company is over its credit limit. A
                                    reason lets you check in anyway.
                                </FieldDescription>
                                <FieldError>
                                    {errors.credit_override_reason}
                                </FieldError>
                            </Field>
                        ) : (
                            <p className="text-sm text-destructive">
                                This company is over its credit limit. Ask
                                someone who may override it.
                            </p>
                        ))}
                    {collection !== null && (
                        <>
                            {!collection.taxesHidden &&
                                collection.taxes.length > 0 && (
                                    <div className="flex flex-col gap-1 text-sm">
                                        <span className="font-medium">
                                            Collected at check-in
                                        </span>
                                        {collection.taxes.map((tax) => (
                                            <AmountRow
                                                key={tax.name}
                                                label={tax.name}
                                                amount={tax.amount}
                                                exempt={tax.exempt}
                                                currency={currency}
                                            />
                                        ))}
                                    </div>
                                )}
                            {collection.deposit !== null && (
                                <FieldLabel htmlFor="check-in-collect_deposit">
                                    <Field orientation="horizontal">
                                        <Checkbox
                                            id="check-in-collect_deposit"
                                            checked={data.collect_deposit}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'collect_deposit',
                                                    checked,
                                                )
                                            }
                                        />
                                        <FieldContent>
                                            <span className="flex justify-between gap-4 text-sm font-medium">
                                                Security deposit
                                                <MoneyText
                                                    amount={collection.deposit}
                                                    currency={currency}
                                                />
                                            </span>
                                        </FieldContent>
                                    </Field>
                                </FieldLabel>
                            )}
                            {nothingToCollect && (
                                <p
                                    className="text-sm text-muted-foreground"
                                    data-slot="no-collection"
                                >
                                    No collection is needed. You can check in
                                    the guest.
                                </p>
                            )}
                            {collecting && (
                                <>
                                    <FieldLabel htmlFor="check-in-collect_now">
                                        <Field orientation="horizontal">
                                            <Checkbox
                                                id="check-in-collect_now"
                                                checked={data.collect_now}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'collect_now',
                                                        checked,
                                                    )
                                                }
                                            />
                                            <FieldContent>
                                                <span className="flex justify-between gap-4 text-sm font-medium">
                                                    Collect now
                                                    <span>
                                                        Total{' '}
                                                        <MoneyText
                                                            amount={String(
                                                                data.collect_now
                                                                    ? collectTotal
                                                                    : 0,
                                                            )}
                                                            currency={currency}
                                                        />
                                                    </span>
                                                </span>
                                                <FieldDescription>
                                                    Untick it to take a part
                                                    payment with Record payment
                                                    on the folio.
                                                </FieldDescription>
                                            </FieldContent>
                                        </Field>
                                    </FieldLabel>
                                    {data.collect_now && (
                                        <div
                                            className={cn(
                                                'grid gap-4',
                                                method?.requiresReference &&
                                                    'sm:grid-cols-2',
                                            )}
                                        >
                                            <Field
                                                data-invalid={
                                                    !!errors.payment_method_id
                                                }
                                            >
                                                <FieldLabel htmlFor="check-in-payment_method_id">
                                                    Method
                                                </FieldLabel>
                                                {paymentOptions ===
                                                undefined ? (
                                                    <Skeleton className="h-9 w-full" />
                                                ) : (
                                                    <EnumSelect
                                                        id="check-in-payment_method_id"
                                                        options={methods.map(
                                                            (one) => ({
                                                                value: String(
                                                                    one.id,
                                                                ),
                                                                label: one.name,
                                                            }),
                                                        )}
                                                        value={methodId}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            form.setData(
                                                                'payment_method_id',
                                                                value,
                                                            )
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
                                                <Field
                                                    data-invalid={
                                                        !!errors.reference
                                                    }
                                                >
                                                    <FieldLabel htmlFor="check-in-reference">
                                                        Reference
                                                    </FieldLabel>
                                                    <Input
                                                        id="check-in-reference"
                                                        value={data.reference}
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'reference',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        maxLength={100}
                                                        autoComplete="off"
                                                        aria-invalid={
                                                            !!errors.reference
                                                        }
                                                    />
                                                    <FieldError>
                                                        {errors.reference}
                                                    </FieldError>
                                                </Field>
                                            )}
                                        </div>
                                    )}
                                </>
                            )}
                        </>
                    )}
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Check in one unit. Shows the room and the registration card status, a Nationality field when a tax needs it, the
 * overrides the user may use, and Collect now (the taxes flagged for check-in and the security deposit) for a user
 * who may record payments. The form sends choices only. The server works out every amount.
 */
export function CheckInDialog(props: Props) {
    return <CheckInForm key={useFreshOnOpen(props.open)} {...props} />;
}
