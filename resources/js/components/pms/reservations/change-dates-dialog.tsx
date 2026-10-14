import { useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useEffect } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
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
import { dates, datesPreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitDatesPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    canWaiveFees: boolean;
    canOverrideRestrictions: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

function nextDay(value: string): string {
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day + 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function ChangeDatesForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    canWaiveFees,
    canOverrideRestrictions,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const inHouse = unit.status.value === 'checked_in';
    const form = useForm({
        arrival_date: unit.arrival,
        departure_date: unit.departure,
        do_not_charge: false,
        reason: '',
        restriction_override_reason: '',
        expected_fee: '',
    });
    const { data, errors } = form;
    const complete = data.arrival_date !== '' && data.departure_date !== '';
    const { preview, reload } = useUnitPreview<Preview>(
        datesPreview.url(key),
        open && complete,
        {
            arrival_date: data.arrival_date,
            departure_date: data.departure_date,
        },
        300,
    );

    useEffect(() => {
        if (errors.expected_fee) {
            reload();
        }
        // Ask again only when the server says the fee changed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.expected_fee]);

    const unchanged =
        data.arrival_date === unit.arrival &&
        data.departure_date === unit.departure;
    const hasFee = preview?.fee != null && Number(preview.fee) > 0;
    const showWaive = canWaiveFees && preview?.isEarlyDeparture && hasFee;
    const ready =
        complete && !unchanged && preview !== null && preview.error === null;

    const submit = () => {
        form.transform((values) => ({
            ...values,
            do_not_charge: Boolean(showWaive) && values.do_not_charge,
            restriction_override_reason: preview?.restricted
                ? values.restriction_override_reason
                : '',
            expected_fee: preview?.isEarlyDeparture ? (preview.fee ?? '') : '',
        }));
        form.put(dates.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    const difference = Number(preview?.difference ?? 0);

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`Change dates · ${unit.roomType}`}
            description={unit.primaryGuest ?? 'No primary guest'}
            errors={errors}
            onSubmit={submit}
            submitLabel="Save dates"
            processing={form.processing}
            submitDisabled={!ready}
        >
            <Field data-invalid={!!errors.arrival_date}>
                <FieldLabel htmlFor="dates-arrival_date">Arrival</FieldLabel>
                {inHouse ? (
                    <p className="flex items-center gap-2 text-sm">
                        <DateText date={unit.arrival} />
                        <span className="flex items-center gap-1 text-muted-foreground">
                            <Lock aria-hidden className="size-3.5" />
                            Locked once the guest is in the house
                        </span>
                    </p>
                ) : (
                    <DatePicker
                        id="dates-arrival_date"
                        value={data.arrival_date}
                        onChange={(value) => {
                            form.setData('arrival_date', value);

                            if (value >= data.departure_date) {
                                form.setData('departure_date', '');
                            }
                        }}
                        aria-invalid={!!errors.arrival_date}
                    />
                )}
                <FieldError>{errors.arrival_date}</FieldError>
            </Field>
            <Field data-invalid={!!errors.departure_date}>
                <FieldLabel htmlFor="dates-departure_date">
                    Departure
                </FieldLabel>
                <DatePicker
                    id="dates-departure_date"
                    value={data.departure_date}
                    min={
                        data.arrival_date
                            ? nextDay(data.arrival_date)
                            : undefined
                    }
                    onChange={(value) => form.setData('departure_date', value)}
                    aria-invalid={!!errors.departure_date}
                />
                {preview !== null && preview.error === null && (
                    <FieldDescription>
                        {preview.nights}{' '}
                        {preview.nights === 1 ? 'night' : 'nights'}
                    </FieldDescription>
                )}
                <FieldError>{errors.departure_date}</FieldError>
            </Field>
            {preview?.error != null && (
                <p className="text-sm text-destructive">{preview.error}</p>
            )}
            {preview !== null && preview.error === null && !unchanged && (
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
            )}
            {preview?.isEarlyDeparture && !unchanged && (
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
                    <FieldLabel htmlFor="dates-do_not_charge">
                        <Field orientation="horizontal">
                            <Checkbox
                                id="dates-do_not_charge"
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
                        <FieldLabel htmlFor="dates-reason">Reason</FieldLabel>
                        <Textarea
                            id="dates-reason"
                            value={data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={500}
                            rows={2}
                            placeholder={
                                data.do_not_charge
                                    ? 'Required with Do not charge'
                                    : 'Optional'
                            }
                            aria-invalid={!!errors.reason}
                        />
                        <FieldError>{errors.reason}</FieldError>
                    </Field>
                </>
            )}
            {preview?.restricted &&
                (canOverrideRestrictions ? (
                    <Field data-invalid={!!errors.restriction_override_reason}>
                        <FieldLabel htmlFor="dates-restriction_override_reason">
                            Restriction override reason
                        </FieldLabel>
                        <Textarea
                            id="dates-restriction_override_reason"
                            value={data.restriction_override_reason}
                            onChange={(event) =>
                                form.setData(
                                    'restriction_override_reason',
                                    event.target.value,
                                )
                            }
                            maxLength={500}
                            rows={2}
                            aria-invalid={!!errors.restriction_override_reason}
                        />
                        <FieldDescription>
                            The new nights break a rate restriction. A reason
                            lets you change the dates anyway.
                        </FieldDescription>
                        <FieldError>
                            {errors.restriction_override_reason}
                        </FieldError>
                    </Field>
                ) : (
                    <p className="text-sm text-destructive">
                        The new nights break a rate restriction. Ask someone who
                        may book past restrictions.
                    </p>
                ))}
        </FormOverlay>
    );
}

/**
 * Change dates, Extend stay and Early departure in one form. The price difference and the early departure fee come
 * from the server as the dates change. An in-house unit can change only its departure.
 */
export function ChangeDatesDialog(props: Props) {
    return <ChangeDatesForm key={useFreshOnOpen(props.open)} {...props} />;
}
