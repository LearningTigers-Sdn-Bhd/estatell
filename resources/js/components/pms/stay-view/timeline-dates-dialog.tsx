import { router, useForm, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { dates as datesRoute, datesPreview } from '@/routes/reservations/units';
import { daysBetween } from './resize-target';
import type { EdgeChange } from './use-edge-drag';

type Preview = App.Data.Reservations.UnitDatesPreviewData;

type Props = {
    change: EdgeChange | null;
    currency: App.Data.Property.CurrencyData | null;
    canWaiveFees: boolean;
    canOverrideRestrictions: boolean;
    onClose: () => void;
};

/** The props after the save, so the grid shows the new dates. */
const RELOAD = ['stays', 'blocks', 'rooms'];

function Change({ old, now }: { old: string; now: string }) {
    return old === now ? (
        <DateText date={now} />
    ) : (
        <span className="flex items-center gap-1.5">
            <span className="text-muted-foreground line-through">
                <DateText date={old} />
            </span>
            <span aria-hidden>→</span>
            <span className="font-medium">
                <DateText date={now} />
            </span>
        </span>
    );
}

function DatesForm({
    change,
    currency,
    canWaiveFees,
    canOverrideRestrictions,
    onClose,
}: Props & { change: EdgeChange }) {
    const { stay, arrivalDate, departureDate } = change;
    const route = { reservation: stay.reservationId, unit: stay.unitId };
    const form = useForm({
        arrival_date: arrivalDate,
        departure_date: departureDate,
        do_not_charge: false,
        reason: '',
        restriction_override_reason: '',
        expected_fee: '',
    });
    const { data, errors } = form;
    const http = useHttp<Record<string, string>, Preview>('post', '', {});
    const [preview, setPreview] = useState<Preview>(change.preview);

    useEffect(() => {
        if (!errors.expected_fee) {
            return;
        }

        http.transform(() => ({
            arrival_date: arrivalDate,
            departure_date: departureDate,
        }));
        http.post(datesPreview.url(route))
            .then(setPreview)
            .catch(() => undefined);
        // Ask again only when the server says the fee changed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.expected_fee]);

    const hasFee = preview.fee !== null && Number(preview.fee) > 0;
    const showWaive = canWaiveFees && preview.isEarlyDeparture && hasFee;
    const needsOverride = preview.restricted && canOverrideRestrictions;
    const difference = Number(preview.difference ?? 0);
    const oldNights = daysBetween(stay.arrivalDate, stay.departureDate);

    const submit = () => {
        form.transform((values) => ({
            ...values,
            do_not_charge: showWaive && values.do_not_charge,
            reason: showWaive ? values.reason : '',
            restriction_override_reason: needsOverride
                ? values.restriction_override_reason
                : '',
            expected_fee: preview.isEarlyDeparture ? (preview.fee ?? '') : '',
        }));
        form.put(datesRoute.url(route), {
            preserveScroll: true,
            preserveState: true,
            only: RELOAD,
            onSuccess: onClose,
            onError: (failed) => {
                // A refusal with no field of its own (sold out, no price) puts the bar back.
                if (failed.form) {
                    toast.add({ title: failed.form, type: 'error' });
                    onClose();
                    router.reload({ only: RELOAD });
                }
            },
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title="Change dates"
            description={
                <>
                    {stay.guestLabel ?? 'No guest named'} · {stay.reference}
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Save dates"
            processing={form.processing}
            submitDisabled={
                (needsOverride &&
                    data.restriction_override_reason.trim() === '') ||
                (showWaive && data.do_not_charge && data.reason.trim() === '')
            }
        >
            <dl className="flex flex-col gap-1 text-sm">
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Arrival</dt>
                    <dd>
                        <Change old={stay.arrivalDate} now={arrivalDate} />
                    </dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Departure</dt>
                    <dd>
                        <Change old={stay.departureDate} now={departureDate} />
                    </dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Nights</dt>
                    <dd>
                        {oldNights === preview.nights ? (
                            preview.nights
                        ) : (
                            <>
                                <span className="text-muted-foreground line-through">
                                    {oldNights}
                                </span>{' '}
                                →{' '}
                                <span className="font-medium">
                                    {preview.nights}
                                </span>
                            </>
                        )}
                    </dd>
                </div>
            </dl>
            <dl className="flex flex-col gap-1 rounded-lg border p-3 text-sm">
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Was</dt>
                    <dd>
                        <MoneyText
                            amount={preview.previousTotal ?? '0'}
                            currency={currency}
                        />
                    </dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Now</dt>
                    <dd>
                        <MoneyText
                            amount={preview.newTotal ?? '0'}
                            currency={currency}
                        />
                    </dd>
                </div>
                <div className="flex justify-between gap-4 font-medium">
                    <dt>Difference</dt>
                    <dd>
                        {difference > 0 && '+ '}
                        {difference < 0 && '- '}
                        <MoneyText
                            amount={String(Math.abs(difference))}
                            currency={currency}
                        />
                    </dd>
                </div>
            </dl>
            {preview.isEarlyDeparture && (
                <div className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">
                        Shorter stay · early departure policy
                    </span>
                    <div className="flex justify-between gap-4">
                        <span className="text-muted-foreground">Fee</span>
                        <span className="font-medium">
                            {hasFee ? (
                                <>
                                    <MoneyText
                                        amount={preview.fee ?? '0'}
                                        currency={currency}
                                    />{' '}
                                    <span className="font-normal text-muted-foreground">
                                        + tax
                                    </span>
                                </>
                            ) : (
                                'No charge'
                            )}
                        </span>
                    </div>
                    <FieldError>{errors.expected_fee}</FieldError>
                </div>
            )}
            {showWaive && (
                <>
                    <FieldLabel htmlFor="timeline-dates-do_not_charge">
                        <Field orientation="horizontal">
                            <Checkbox
                                id="timeline-dates-do_not_charge"
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
                                    The fee is not posted to the folio. This is
                                    logged with your name.
                                </FieldDescription>
                            </FieldContent>
                        </Field>
                    </FieldLabel>
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="timeline-dates-reason">
                            Reason
                        </FieldLabel>
                        <Textarea
                            id="timeline-dates-reason"
                            value={data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={500}
                            rows={2}
                            placeholder="e.g. Guest left for a family emergency"
                            aria-invalid={!!errors.reason}
                        />
                        <FieldDescription>
                            Required with Do not charge.
                        </FieldDescription>
                        <FieldError>{errors.reason}</FieldError>
                    </Field>
                </>
            )}
            {needsOverride && (
                <Field data-invalid={!!errors.restriction_override_reason}>
                    <FieldLabel htmlFor="timeline-dates-restriction_override_reason">
                        Override reason
                    </FieldLabel>
                    <Textarea
                        id="timeline-dates-restriction_override_reason"
                        value={data.restriction_override_reason}
                        onChange={(event) =>
                            form.setData(
                                'restriction_override_reason',
                                event.target.value,
                            )
                        }
                        maxLength={500}
                        rows={2}
                        placeholder="e.g. Regular guest, manager approved"
                        aria-invalid={!!errors.restriction_override_reason}
                    />
                    <FieldDescription>
                        The new nights break a rate restriction. A reason lets
                        you change the dates anyway. It is logged.
                    </FieldDescription>
                    <FieldError>
                        {errors.restriction_override_reason}
                    </FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}

/**
 * The confirm Dialog after a stay's edge is dropped on a new date. Nothing is saved before Save dates. It sends the
 * same request as Change dates on the reservation page, and reads the preview again when the early departure fee
 * changed. A refusal with no field (sold out, no price) closes it with a toast and reloads the grid.
 */
export function TimelineDatesDialog(props: Props) {
    const key = useFreshOnOpen(props.change !== null);

    if (props.change === null) {
        return null;
    }

    return <DatesForm key={key} {...props} change={props.change} />;
}
