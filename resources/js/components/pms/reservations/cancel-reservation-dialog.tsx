import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cancel, cancelPreview } from '@/routes/reservations';

type Preview = App.Data.Reservations.CancelAllPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: App.Data.Reservations.ReservationData;
    canWaiveFees: boolean;
    currency: App.Enums.Currency | null;
};

const cents = (amount: string | null) => Math.round(Number(amount ?? 0) * 100);
const toAmount = (value: number) => (Math.abs(value) / 100).toFixed(2);

function CancelReservationForm({
    open,
    onOpenChange,
    reservation,
    canWaiveFees,
    currency,
}: Props) {
    const key = { reservation: reservation.id };
    const { preview, reload } = useUnitPreview<Preview>(
        cancelPreview.url(key),
        open,
    );
    const [picked, setPicked] = useState<number[] | null>(null);
    const form = useForm({
        do_not_charge: false,
        reason: '',
        remove_add_ons: false,
    });
    const { data } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const eligible = preview?.rows.filter((row) => row.canCancel) ?? [];
    const selected = (picked ?? eligible.map((row) => row.unitId)).filter(
        (id) => eligible.some((row) => row.unitId === id),
    );
    const chosen = eligible.filter((row) => selected.includes(row.unitId));
    const hasFee = chosen.some((row) => cents(row.fee) > 0);
    const waive = canWaiveFees && hasFee && data.do_not_charge;
    const fees = waive
        ? 0
        : chosen.reduce((sum, row) => sum + cents(row.fee), 0);
    const feesWithTax = waive
        ? 0
        : chosen.reduce((sum, row) => sum + cents(row.feeTotal), 0);
    const owed = feesWithTax - cents(preview?.paid ?? '0');
    const addOns = chosen.reduce((sum, row) => sum + row.unpostedAddOns, 0);
    const label =
        selected.length === 1
            ? 'Cancel 1 room'
            : `Cancel ${selected.length} rooms`;

    useEffect(() => {
        if (errors.expected_fee) {
            reload();
        }
        // Ask again only when the server says a fee changed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.expected_fee]);

    const toggle = (unitId: number, checked: boolean) =>
        setPicked(
            checked
                ? [...selected, unitId]
                : selected.filter((id) => id !== unitId),
        );

    const submit = () => {
        form.transform((values) => ({
            ...values,
            do_not_charge: waive,
            unit_ids: selected,
            expected_fees: Object.fromEntries(
                chosen.map((row) => [row.unitId, row.fee ?? '']),
            ),
        }));
        form.post(cancel.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    const blocked =
        preview === null || preview.error !== null || selected.length === 0;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Cancel reservation"
            description={`${reservation.reference} · ${reservation.title}`}
            errors={errors}
            onSubmit={submit}
            submitLabel={selected.length === 0 ? 'Cancel rooms' : label}
            submitVariant="destructive"
            processing={form.processing}
            submitDisabled={blocked}
            loading={preview === null}
            footer={
                <>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Keep reservation
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={form.processing || blocked}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        {selected.length === 0 ? 'Cancel rooms' : label}
                    </Button>
                </>
            }
        >
            {preview !== null && (
                <>
                    {preview.error !== null && (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    )}
                    {preview.request !== null && (
                        <Alert>
                            <AlertDescription>
                                {preview.request.by} requested cancellation on{' '}
                                <DateText date={preview.request.at} />.
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="flex flex-col gap-2">
                        {preview.rows.map((row) => (
                            <Field
                                key={row.unitId}
                                orientation="horizontal"
                                data-disabled={!row.canCancel}
                            >
                                <Checkbox
                                    id={`cancel-reservation-${row.unitId}`}
                                    checked={selected.includes(row.unitId)}
                                    disabled={!row.canCancel}
                                    onCheckedChange={(checked) =>
                                        toggle(row.unitId, checked)
                                    }
                                />
                                <FieldContent>
                                    <FieldLabel
                                        htmlFor={`cancel-reservation-${row.unitId}`}
                                        className="flex flex-wrap justify-between gap-x-4"
                                    >
                                        <span>
                                            {row.label}
                                            {row.guest ? ` · ${row.guest}` : ''}
                                        </span>
                                        {row.canCancel && (
                                            <span className="font-normal text-muted-foreground">
                                                {cents(row.fee) === 0 ? (
                                                    'No charge'
                                                ) : (
                                                    <>
                                                        Fee{' '}
                                                        <MoneyText
                                                            amount={
                                                                row.fee ?? '0'
                                                            }
                                                            currency={currency}
                                                        />
                                                    </>
                                                )}
                                            </span>
                                        )}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {row.stayLabel ?? (
                                            <>
                                                <DateText date={row.arrival} />{' '}
                                                →{' '}
                                                <DateText
                                                    date={row.departure}
                                                />
                                            </>
                                        )}
                                        {row.note !== null && ` · ${row.note}`}
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        ))}
                    </div>
                    <FieldError>{errors.unit_ids}</FieldError>
                    {preview.freeUntilDays !== null && (
                        <p className="text-sm text-muted-foreground">
                            Cancellation policy: free until{' '}
                            {preview.freeUntilDays}{' '}
                            {preview.freeUntilDays === 1 ? 'day' : 'days'}{' '}
                            before arrival.
                        </p>
                    )}
                    {chosen.length > 0 && (
                        <div className="flex flex-col gap-1 text-sm">
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Fees · {chosen.length}{' '}
                                    {chosen.length === 1 ? 'room' : 'rooms'}
                                </span>
                                <span className="font-medium">
                                    {fees === 0 ? (
                                        'No charge'
                                    ) : (
                                        <>
                                            <MoneyText
                                                amount={toAmount(fees)}
                                                currency={currency}
                                            />{' '}
                                            <span className="font-normal text-muted-foreground">
                                                + tax ={' '}
                                                <MoneyText
                                                    amount={toAmount(
                                                        feesWithTax,
                                                    )}
                                                    currency={null}
                                                    plain
                                                />
                                            </span>
                                        </>
                                    )}
                                </span>
                            </div>
                            <FieldError>{errors.expected_fee}</FieldError>
                        </div>
                    )}
                    {canWaiveFees && hasFee && (
                        <FieldLabel htmlFor="cancel-reservation-do_not_charge">
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="cancel-reservation-do_not_charge"
                                    checked={data.do_not_charge}
                                    onCheckedChange={(checked) =>
                                        form.setData('do_not_charge', checked)
                                    }
                                />
                                <FieldContent>
                                    <span className="text-sm font-medium">
                                        Do not charge
                                    </span>
                                    <FieldDescription>
                                        No fee is posted for any room. This is
                                        logged with your name.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </FieldLabel>
                    )}
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="cancel-reservation-reason">
                            Reason
                        </FieldLabel>
                        <Textarea
                            id="cancel-reservation-reason"
                            value={data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={500}
                            rows={2}
                            placeholder={
                                waive
                                    ? 'Required with Do not charge'
                                    : 'Optional'
                            }
                            aria-invalid={!!errors.reason}
                        />
                        <FieldError>{errors.reason}</FieldError>
                    </Field>
                    {addOns > 0 && (
                        <FieldLabel htmlFor="cancel-reservation-remove_add_ons">
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="cancel-reservation-remove_add_ons"
                                    checked={data.remove_add_ons}
                                    onCheckedChange={(checked) =>
                                        form.setData('remove_add_ons', checked)
                                    }
                                />
                                <FieldContent>
                                    <span className="text-sm font-medium">
                                        Also remove add-ons
                                    </span>
                                    <FieldDescription>
                                        Removes {addOns} add-on
                                        {addOns === 1 ? '' : 's'} not posted to
                                        a folio yet.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </FieldLabel>
                    )}
                    {chosen.length > 0 && (
                        <div className="flex flex-col gap-1 text-sm">
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Paid on this reservation
                                </span>
                                <MoneyText
                                    amount={preview.paid}
                                    currency={currency}
                                />
                            </div>
                            {owed !== 0 && (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        {owed > 0
                                            ? `After the fees, ${reservation.companyName ?? 'the guest'} owes`
                                            : 'To refund after the fees'}
                                    </span>
                                    <span className="font-medium">
                                        <MoneyText
                                            amount={toAmount(owed)}
                                            currency={currency}
                                        />
                                    </span>
                                </div>
                            )}
                            {owed < 0 && (
                                <p className="text-muted-foreground">
                                    Refund it on the folio tab after cancelling.
                                </p>
                            )}
                        </div>
                    )}
                    <p className="text-sm text-muted-foreground">
                        The rooms are released. A checked-in room is not
                        cancelled here.
                    </p>
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Cancels several units of a reservation in one step, all or nothing. A Confirmed unit is ticked by default; the
 * fees are read-only text from the server and the form sends the ticked units, the fee it showed for each and the
 * choices, never an amount.
 */
export function CancelReservationDialog(props: Props) {
    return (
        <CancelReservationForm key={useFreshOnOpen(props.open)} {...props} />
    );
}
