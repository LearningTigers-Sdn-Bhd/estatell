import { useForm, useHttp } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { quote as quoteRoute, store, update } from '@/routes/facility-bookings';

type Facility = App.Data.Facilities.FacilityOptionData;
type Booking = App.Data.Facilities.FacilityBookingRowData;
type Quote = App.Data.Facilities.FacilityBookingQuoteData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    eventId: number;
    /** The event's first and last day, `Y-m-d`. Bookings must fall inside them. */
    startsOn: string;
    endsOn: string;
    /** What Charge to shows, read only for an event: its folio. */
    chargeTo: string;
    facilities: Facility[];
    /** The booking to edit. Without it the Sheet books a new one. */
    booking?: Booking;
    currency: App.Data.Property.CurrencyData | null;
};

type FormData = {
    facility_id: string;
    booking_date: string;
    starts_at: string;
    ends_at: string;
    quantity: string;
    unit_amount: string;
    notes: string;
};

const sessionKey = (startsAt: string, endsAt: string) =>
    `${startsAt}-${endsAt}`;

function BookFacilityForm({
    open,
    onOpenChange,
    eventId,
    startsOn,
    endsOn,
    chargeTo,
    facilities,
    booking,
    currency,
}: Props) {
    const form = useForm<FormData>({
        facility_id: booking ? String(booking.facilityId) : '',
        booking_date: booking?.bookingDate ?? startsOn,
        starts_at: booking?.startsAt ?? '',
        ends_at: booking?.endsAt ?? '',
        quantity: booking ? String(booking.quantity) : '1',
        unit_amount: booking?.unitAmount ?? '',
        notes: booking?.notes ?? '',
    });
    const { data, setData } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const facility = facilities.find(
        (one) => String(one.id) === data.facility_id,
    );
    const hasSessions = (facility?.sessions.length ?? 0) > 0;
    const chargedByHand = facility !== undefined && facility.listPrice === null;

    const http = useHttp<Record<string, string | number | null>, Quote>(
        'post',
        quoteRoute.url(),
        {},
    );
    const [quote, setQuote] = useState<Quote | null>(null);

    useEffect(() => {
        if (!open || data.facility_id === '' || data.booking_date === '') {
            setQuote(null);

            return;
        }

        let current = true;
        const timer = window.setTimeout(() => {
            http.transform(() => ({
                event_id: eventId,
                facility_id: data.facility_id,
                booking_id: booking?.id ?? null,
                booking_date: data.booking_date,
                starts_at: data.starts_at,
                ends_at: data.ends_at,
                quantity: data.quantity === '' ? 1 : data.quantity,
                unit_amount: chargedByHand ? null : data.unit_amount,
            }));
            http.submit()
                .then((answer) => current && setQuote(answer))
                .catch(() => current && setQuote(null));
        }, 300);

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
        // The quote depends only on what staff typed or picked.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        open,
        data.facility_id,
        data.booking_date,
        data.starts_at,
        data.ends_at,
        data.quantity,
        data.unit_amount,
    ]);

    const facilityOptions: EnumOption[] = facilities.map((one) => ({
        value: String(one.id),
        label: one.name,
        description: `${one.sharing === 'shared' ? 'Shared' : 'Private'} · ${one.capacity} ${one.capacityUnitLabel}`,
    }));

    const sessionOptions: EnumOption[] = (facility?.sessions ?? []).map(
        (session) => {
            const places = quote?.sessions.find(
                (one) =>
                    one.startsAt === session.startsAt &&
                    one.endsAt === session.endsAt,
            );

            return {
                value: sessionKey(session.startsAt, session.endsAt),
                label:
                    places === undefined
                        ? `${session.startsAt}–${session.endsAt}`
                        : `${session.startsAt}–${session.endsAt} · ${places.left} of ${facility?.capacity} left`,
            };
        },
    );

    const pickFacility = (value: string) => {
        const picked = facilities.find((one) => String(one.id) === value);

        setData((current) => ({
            ...current,
            facility_id: value,
            starts_at: '',
            ends_at: '',
            unit_amount: picked?.listPrice ?? '',
        }));
    };

    const pickSession = (value: string) => {
        const [startsAt, endsAt] = value.split('-');

        setData((current) => ({
            ...current,
            starts_at: startsAt,
            ends_at: endsAt,
        }));
    };

    const priceChanged =
        facility?.listPrice != null &&
        data.unit_amount !== '' &&
        Number(data.unit_amount) !== Number(facility.listPrice);

    const submit = () => {
        form.transform((values) => ({
            ...(booking
                ? {}
                : { event_id: eventId, facility_id: values.facility_id }),
            booking_date: values.booking_date,
            starts_at: values.starts_at,
            ends_at: values.ends_at,
            quantity: values.quantity,
            unit_amount: chargedByHand ? null : values.unit_amount || null,
            notes: values.notes,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (booking) {
            form.put(update.url({ facilityBooking: booking.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title={booking ? 'Edit booking' : 'Book facility'}
            description={
                booking
                    ? undefined
                    : 'Book a hall, equipment or an activity for this event.'
            }
            errors={errors}
            dirty={form.isDirty}
            discardTitle="Discard this booking?"
            onSubmit={submit}
            submitLabel={booking ? 'Save booking' : 'Book'}
            processing={form.processing}
            submitDisabled={
                data.facility_id === '' ||
                data.starts_at === '' ||
                data.ends_at === '' ||
                data.quantity === '' ||
                (quote?.refusal ?? null) !== null
            }
        >
            <FieldGroup>
                <Field data-invalid={!!errors.facility_id}>
                    <FieldLabel htmlFor="booking-facility">Facility</FieldLabel>
                    <EnumSelect
                        id="booking-facility"
                        name="facility_id"
                        options={facilityOptions}
                        value={data.facility_id}
                        onValueChange={pickFacility}
                        placeholder="Choose a facility"
                        disabled={booking !== undefined}
                        aria-invalid={!!errors.facility_id}
                    />
                    <FieldError>{errors.facility_id}</FieldError>
                </Field>

                <Field data-invalid={!!errors.booking_date}>
                    <FieldLabel htmlFor="booking-date">Date</FieldLabel>
                    <DatePicker
                        id="booking-date"
                        value={data.booking_date}
                        onChange={(value) => setData('booking_date', value)}
                        min={startsOn}
                        max={endsOn}
                        placeholder="e.g. 12/12/2026"
                        aria-invalid={!!errors.booking_date}
                    />
                    <FieldError>{errors.booking_date}</FieldError>
                </Field>

                {hasSessions ? (
                    <Field data-invalid={!!errors.starts_at}>
                        <FieldLabel htmlFor="booking-session">
                            Session
                        </FieldLabel>
                        <EnumSelect
                            id="booking-session"
                            name="session"
                            options={sessionOptions}
                            value={
                                data.starts_at === ''
                                    ? ''
                                    : sessionKey(data.starts_at, data.ends_at)
                            }
                            onValueChange={pickSession}
                            placeholder="Choose a session"
                            aria-invalid={!!errors.starts_at}
                        />
                        <FieldError>{errors.starts_at}</FieldError>
                    </Field>
                ) : (
                    <div className="grid grid-cols-2 gap-4">
                        <Field data-invalid={!!errors.starts_at}>
                            <FieldLabel htmlFor="booking-from">From</FieldLabel>
                            <Input
                                id="booking-from"
                                type="time"
                                value={data.starts_at}
                                onChange={(change) =>
                                    setData('starts_at', change.target.value)
                                }
                                aria-invalid={!!errors.starts_at}
                            />
                            <FieldError>{errors.starts_at}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.ends_at}>
                            <FieldLabel htmlFor="booking-to">To</FieldLabel>
                            <Input
                                id="booking-to"
                                type="time"
                                value={data.ends_at}
                                onChange={(change) =>
                                    setData('ends_at', change.target.value)
                                }
                                aria-invalid={!!errors.ends_at}
                            />
                            <FieldError>{errors.ends_at}</FieldError>
                        </Field>
                    </div>
                )}

                <Field data-invalid={!!errors.quantity}>
                    <FieldLabel htmlFor="booking-quantity">Quantity</FieldLabel>
                    <div className="flex items-center gap-2">
                        <Input
                            id="booking-quantity"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            className="w-28"
                            placeholder="e.g. 20"
                            value={data.quantity}
                            onChange={(change) =>
                                setData('quantity', change.target.value)
                            }
                            aria-invalid={!!errors.quantity}
                        />
                        {facility && (
                            <span className="text-sm text-muted-foreground">
                                {facility.capacityUnitLabel}
                            </span>
                        )}
                    </div>
                    <FieldError>{errors.quantity}</FieldError>
                </Field>

                {facility && !chargedByHand && (
                    <Field data-invalid={!!errors.unit_amount}>
                        <div className="flex items-baseline justify-between gap-2">
                            <FieldLabel htmlFor="booking-price">
                                Price{currency ? ` (${currency.code})` : ''}
                            </FieldLabel>
                            <span className="text-sm text-muted-foreground">
                                {facility.rateBasisLabel}
                            </span>
                        </div>
                        <MoneyInput
                            id="booking-price"
                            currency={currency}
                            value={data.unit_amount}
                            onChange={(value) => setData('unit_amount', value)}
                            invalid={!!errors.unit_amount}
                        />
                        {priceChanged && facility.listPrice !== null && (
                            <FieldDescription>
                                List price{' '}
                                <MoneyText
                                    amount={facility.listPrice}
                                    currency={currency}
                                    plain
                                />
                            </FieldDescription>
                        )}
                        <FieldError>{errors.unit_amount}</FieldError>
                    </Field>
                )}

                <Field>
                    <FieldLabel>Charge to</FieldLabel>
                    <p className="text-sm">{chargeTo}</p>
                    {chargedByHand && (
                        <FieldDescription>
                            Charged by hand: post the charge on the folio
                            yourself.
                        </FieldDescription>
                    )}
                </Field>

                <Field data-invalid={!!errors.notes}>
                    <FieldLabel htmlFor="booking-notes">Notes</FieldLabel>
                    <Textarea
                        id="booking-notes"
                        maxLength={2000}
                        placeholder="e.g. Round tables, stage on the left"
                        value={data.notes}
                        onChange={(change) =>
                            setData('notes', change.target.value)
                        }
                        aria-invalid={!!errors.notes}
                    />
                    <FieldError>{errors.notes}</FieldError>
                </Field>

                {quote?.refusal && (
                    <Alert variant="destructive" aria-live="polite">
                        <TriangleAlert />
                        <AlertDescription>{quote.refusal}</AlertDescription>
                    </Alert>
                )}
                {quote?.warning && (
                    <Alert aria-live="polite">
                        <TriangleAlert />
                        <AlertDescription>{quote.warning}</AlertDescription>
                    </Alert>
                )}
                {quote?.amount && (
                    <p
                        className="text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        <MoneyText
                            amount={quote.amount}
                            currency={currency}
                            plain
                        />
                        {quote.taxes.map((tax) => (
                            <span key={tax.name}>
                                {tax.inclusive ? ' incl. ' : ' + '}
                                {tax.name}{' '}
                                <MoneyText
                                    amount={tax.amount}
                                    currency={currency}
                                    plain
                                />
                            </span>
                        ))}
                    </p>
                )}
            </FieldGroup>
        </FormOverlay>
    );
}

/**
 * Book facility and Edit booking on an event. The server sends the price line, the clash refusal, the Tentative hold
 * warning and the places left in each session; the browser calculates none of them.
 * See docs/33-facilities/04-facility-bookings.md#book-facility-sheet.
 */
export function BookFacilitySheet(props: Props) {
    return <BookFacilityForm key={useFreshOnOpen(props.open)} {...props} />;
}
