import { useForm, useHttp } from '@inertiajs/react';
import { Building2, TriangleAlert, User } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { CompanySelect } from '@/components/pms/company-select';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { GuestPicker } from '@/components/pms/guest-picker';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { quote as quoteRoute, store, update } from '@/routes/facility-bookings';
import { store as storeFolio } from '@/routes/facility-bookings/folios';

type Facility = App.Data.Facilities.FacilityOptionData;
type Booking = App.Data.Facilities.FacilityBookingRowData;
type Quote = App.Data.Facilities.FacilityBookingQuoteData;
type Guest = App.Data.Guests.GuestOptionData;
type Company = App.Data.CityLedger.CompanyOptionData;

/** Who the booking belongs to. An event charges its own folio; a reservation picks one in Charge to. */
export type BookingOwner =
    | {
          type: 'event';
          id: number;
          /** The event's first and last day, `Y-m-d`. Bookings must fall inside them. */
          startsOn: string;
          endsOn: string;
          /** What Charge to shows, read only: the event folio. */
          chargeTo: string;
      }
    | {
          type: 'reservation';
          id: number;
          /** The business date when it is inside the stay, else the arrival date. Any date is allowed. */
          defaultDate: string;
          chargeToOptions: App.Data.Facilities.ChargeToOptionData[];
          /** The routed folio of each facility, by facility id. */
          defaultChargeTo: Record<string, string | null>;
          /** Shows **+ New third-party folio…** (needs `folio.transfer`). */
          canCreateFolio: boolean;
          canViewCompany: boolean;
          companies?: Company[];
      };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    owner: BookingOwner;
    facilities: Facility[];
    /** The booking to edit. Without it the Sheet books a new one. */
    booking?: Booking;
    currency: App.Data.Property.CurrencyData | null;
    /** A new booking starts on this facility (by id) and this date, as Book facility on the Timeline does. */
    initialFacilityId?: number;
    initialDate?: string;
    /** Shows "For {ownerLabel}" with **Change**, which goes back to the Timeline's For step. */
    ownerLabel?: string;
    onChangeOwner?: () => void;
};

type FormData = {
    facility_id: string;
    booking_date: string;
    starts_at: string;
    ends_at: string;
    quantity: string;
    unit_amount: string;
    notes: string;
    charge_to: string;
    /** A private booking (a charter): the whole facility at its time, at the private price. */
    private: boolean;
};

/** The Charge to choice that swaps the Sheet to the payer step. */
const NEW_FOLIO = 'new';

const sessionKey = (startsAt: string, endsAt: string) =>
    `${startsAt}-${endsAt}`;

function BookFacilityForm({
    open,
    onOpenChange,
    owner,
    facilities,
    booking,
    currency,
    initialFacilityId,
    initialDate,
    ownerLabel,
    onChangeOwner,
}: Props) {
    const isReservation = owner.type === 'reservation';
    const startFacility = booking
        ? undefined
        : facilities.find((one) => one.id === initialFacilityId);
    const form = useForm<FormData>({
        facility_id: booking
            ? String(booking.facilityId)
            : startFacility
              ? String(startFacility.id)
              : '',
        booking_date:
            booking?.bookingDate ??
            initialDate ??
            (owner.type === 'event' ? owner.startsOn : owner.defaultDate),
        starts_at: booking?.startsAt ?? '',
        ends_at: booking?.endsAt ?? '',
        quantity: booking ? String(booking.quantity) : '1',
        unit_amount: booking?.unitAmount ?? startFacility?.listPrice ?? '',
        notes: booking?.notes ?? '',
        private: booking?.private ?? false,
        charge_to:
            booking?.chargeTo ??
            (startFacility && owner.type === 'reservation'
                ? (owner.defaultChargeTo[String(startFacility.id)] ??
                  owner.chargeToOptions[0]?.value ??
                  '')
                : ''),
    });
    const { data, setData } = form;
    const [step, setStep] = useState<'booking' | 'payer'>('booking');
    const [chargeToPicked, setChargeToPicked] = useState(booking !== undefined);
    const errors = form.errors as Partial<Record<string, string>>;
    const facility = facilities.find(
        (one) => String(one.id) === data.facility_id,
    );
    const hasSessions = (facility?.sessions.length ?? 0) > 0;
    const listPrice = data.private
        ? (facility?.privatePrice ?? null)
        : (facility?.listPrice ?? null);
    const chargedByHand = facility !== undefined && listPrice === null;

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
                ...(isReservation
                    ? { reservation_id: owner.id, charge_to: data.charge_to }
                    : { event_id: owner.id }),
                facility_id: data.facility_id,
                booking_id: booking?.id ?? null,
                booking_date: data.booking_date,
                starts_at: data.starts_at,
                ends_at: data.ends_at,
                quantity: data.quantity === '' ? 1 : data.quantity,
                unit_amount: chargedByHand ? null : data.unit_amount,
                private: data.private ? 1 : 0,
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
        data.charge_to,
        data.private,
    ]);

    const facilityOptions: EnumOption[] = facilities.map((one) => ({
        value: String(one.id),
        label: one.name,
        description: one.capacityLabel,
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
            private: false,
            charge_to:
                owner.type === 'reservation' && !chargeToPicked
                    ? (owner.defaultChargeTo[value] ??
                      owner.chargeToOptions[0]?.value ??
                      '')
                    : current.charge_to,
        }));
    };

    const chargeToOptions: EnumOption[] =
        owner.type === 'reservation'
            ? [
                  ...owner.chargeToOptions,
                  ...(booking?.chargeTo &&
                  !owner.chargeToOptions.some(
                      (option) => option.value === booking.chargeTo,
                  )
                      ? [
                            {
                                value: booking.chargeTo,
                                label: booking.chargeToLabel ?? 'Folio',
                                disabled: true,
                            },
                        ]
                      : []),
                  ...(owner.canCreateFolio
                      ? [
                            {
                                value: NEW_FOLIO,
                                label: '+ New third-party folio…',
                            },
                        ]
                      : []),
              ]
            : [];

    const pickChargeTo = (value: string) => {
        if (value === NEW_FOLIO) {
            setStep('payer');

            return;
        }

        setChargeToPicked(true);
        setData('charge_to', value);
    };

    const payerForm = useForm<{
        payerType: string;
        payer: Guest | null;
        company: string;
    }>({ payerType: 'guest', payer: null, company: '' });
    const payerErrors = payerForm.errors as Partial<Record<string, string>>;
    const payerIsCompany = payerForm.data.payerType === 'company';

    useFormOptions(
        open &&
            step === 'payer' &&
            owner.type === 'reservation' &&
            owner.canViewCompany,
        owner.type === 'reservation' && owner.companies !== undefined,
        'companies',
    );

    const createFolio = () => {
        payerForm.transform((values) =>
            values.payerType === 'company'
                ? { payer_company_id: values.company || null }
                : { payer_guest_id: values.payer?.id ?? null },
        );
        payerForm.post(storeFolio.url({ reservation: owner.id }), {
            preserveScroll: true,
            preserveState: true,
            except: KEEP_OPTIONS,
            onSuccess: (page) => {
                const created = (
                    page.flash as { chargeToFolioCreated?: string } | undefined
                )?.chargeToFolioCreated;

                if (created) {
                    setChargeToPicked(true);
                    setData('charge_to', created);
                }

                payerForm.reset();
                setStep('booking');
            },
        });
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
        listPrice !== null &&
        data.unit_amount !== '' &&
        Number(data.unit_amount) !== Number(listPrice);

    const togglePrivate = (checked: boolean) =>
        setData((current) => ({
            ...current,
            private: checked,
            unit_amount:
                (checked ? facility?.privatePrice : facility?.listPrice) ?? '',
        }));

    const submit = () => {
        form.transform((values) => ({
            ...(booking
                ? {}
                : {
                      ...(isReservation
                          ? { reservation_id: owner.id }
                          : { event_id: owner.id }),
                      facility_id: values.facility_id,
                  }),
            ...(isReservation ? { charge_to: values.charge_to } : {}),
            booking_date: values.booking_date,
            starts_at: values.starts_at,
            ends_at: values.ends_at,
            quantity: values.quantity,
            unit_amount: chargedByHand ? null : values.unit_amount || null,
            notes: values.notes,
            private: values.private,
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

    if (step === 'payer' && owner.type === 'reservation') {
        return (
            <FormOverlay
                variant="sheet"
                open={open}
                onOpenChange={onOpenChange}
                title="New third-party folio"
                description="A folio with its own payer, for example a company that pays the hall. It is picked in Charge to."
                errors={payerErrors}
                dirty
                discardTitle="Discard this booking?"
                onSubmit={createFolio}
                submitLabel="Create folio"
                processing={payerForm.processing}
                footer={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                payerForm.clearErrors();
                                setStep('booking');
                            }}
                        >
                            Back
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                payerForm.processing ||
                                (payerIsCompany
                                    ? payerForm.data.company === ''
                                    : payerForm.data.payer === null)
                            }
                            focusableWhenDisabled
                        >
                            {payerForm.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Create folio
                        </Button>
                    </>
                }
            >
                {owner.canViewCompany && (
                    <ChoiceCards
                        id="booking-payer-type"
                        legend="Payer"
                        choices={[
                            {
                                value: 'guest',
                                label: 'Guest',
                                description: 'A guest of the property.',
                                icon: User,
                            },
                            {
                                value: 'company',
                                label: 'Company',
                                description: 'Settled to the city ledger.',
                                icon: Building2,
                            },
                        ]}
                        value={payerForm.data.payerType}
                        onValueChange={(value) =>
                            payerForm.setData('payerType', value)
                        }
                    />
                )}
                {payerIsCompany ? (
                    <Field data-invalid={!!payerErrors.payer_company_id}>
                        <FieldLabel htmlFor="booking-payer-company">
                            Company
                        </FieldLabel>
                        <CompanySelect
                            id="booking-payer-company"
                            value={payerForm.data.company}
                            onValueChange={(value) =>
                                payerForm.setData('company', value)
                            }
                            companies={owner.companies ?? []}
                            invalid={!!payerErrors.payer_company_id}
                        />
                        <FieldError>{payerErrors.payer_company_id}</FieldError>
                    </Field>
                ) : (
                    <Field data-invalid={!!payerErrors.payer_guest_id}>
                        <FieldLabel htmlFor="booking-payer-guest">
                            Guest
                        </FieldLabel>
                        <GuestPicker
                            id="booking-payer-guest"
                            value={payerForm.data.payer}
                            onChange={(guest) =>
                                payerForm.setData('payer', guest)
                            }
                            invalid={!!payerErrors.payer_guest_id}
                        />
                        <FieldError>{payerErrors.payer_guest_id}</FieldError>
                    </Field>
                )}
            </FormOverlay>
        );
    }

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title={booking ? 'Edit booking' : 'Book facility'}
            description={
                booking
                    ? undefined
                    : `Book a hall, equipment or an activity for this ${isReservation ? 'reservation' : 'event'}.`
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
                (isReservation && data.charge_to === '') ||
                (quote?.refusal ?? null) !== null
            }
        >
            <FieldGroup>
                {ownerLabel !== undefined && (
                    <Field orientation="horizontal">
                        <FieldLabel>For</FieldLabel>
                        <span className="min-w-0 flex-1 truncate text-sm">
                            {ownerLabel}
                        </span>
                        {onChangeOwner && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={onChangeOwner}
                            >
                                Change
                            </Button>
                        )}
                    </Field>
                )}
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
                        min={
                            owner.type === 'event' ? owner.startsOn : undefined
                        }
                        max={owner.type === 'event' ? owner.endsOn : undefined}
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

                {facility?.privatePrice != null && (
                    <Field
                        orientation="horizontal"
                        className="items-start"
                        data-invalid={!!errors.private}
                    >
                        <Switch
                            id="booking-private"
                            checked={data.private}
                            onCheckedChange={togglePrivate}
                            className="mt-0.5"
                        />
                        <FieldContent>
                            <FieldLabel htmlFor="booking-private">
                                Private booking
                            </FieldLabel>
                            <FieldDescription>
                                Takes the whole {facility.name} at this time, at
                                one price for the group. Nobody else can join.
                            </FieldDescription>
                            <FieldError>{errors.private}</FieldError>
                        </FieldContent>
                    </Field>
                )}

                {facility && !chargedByHand && (
                    <Field data-invalid={!!errors.unit_amount}>
                        <div className="flex items-baseline justify-between gap-2">
                            <FieldLabel htmlFor="booking-price">
                                Price{currency ? ` (${currency.code})` : ''}
                            </FieldLabel>
                            <span className="text-sm text-muted-foreground">
                                {data.private
                                    ? 'Per booking'
                                    : facility.rateBasisLabel}
                            </span>
                        </div>
                        <MoneyInput
                            id="booking-price"
                            currency={currency}
                            value={data.unit_amount}
                            onChange={(value) => setData('unit_amount', value)}
                            invalid={!!errors.unit_amount}
                        />
                        {priceChanged && listPrice !== null && (
                            <FieldDescription>
                                List price{' '}
                                <MoneyText
                                    amount={listPrice}
                                    currency={currency}
                                    plain
                                />
                            </FieldDescription>
                        )}
                        <FieldError>{errors.unit_amount}</FieldError>
                    </Field>
                )}

                <Field data-invalid={!!errors.charge_to}>
                    {owner.type === 'reservation' ? (
                        <>
                            <FieldLabel htmlFor="booking-charge-to">
                                Charge to
                            </FieldLabel>
                            <EnumSelect
                                id="booking-charge-to"
                                name="charge_to"
                                options={chargeToOptions}
                                value={data.charge_to}
                                onValueChange={pickChargeTo}
                                placeholder="Choose a folio"
                                aria-invalid={!!errors.charge_to}
                            />
                            <FieldError>{errors.charge_to}</FieldError>
                        </>
                    ) : (
                        <>
                            <FieldLabel>Charge to</FieldLabel>
                            <p className="text-sm">{owner.chargeTo}</p>
                        </>
                    )}
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
 * Book facility and Edit booking on an event or a reservation. A reservation picks the folio in Charge to, and
 * **+ New third-party folio…** swaps the Sheet body to a payer step that creates the folio and comes back with it
 * picked (one overlay only). The server sends the price line, the clash refusal, the Tentative hold
 * warning and the places left in each session; the browser calculates none of them.
 * See docs/33-facilities/04-facility-bookings.md#book-facility-sheet.
 */
export function BookFacilitySheet(props: Props) {
    return <BookFacilityForm key={useFreshOnOpen(props.open)} {...props} />;
}
