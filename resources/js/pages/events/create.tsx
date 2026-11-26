import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/react';
import { Building2, NotebookText, Plus, Tent, User, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { CompanySelect } from '@/components/pms/company-select';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    chargedByHand,
    EventFacilityLines,
    newFacilityLine,
} from '@/components/pms/events/event-facility-lines';
import type { FacilityLine } from '@/components/pms/events/event-facility-lines';
import { NewEventSummary } from '@/components/pms/events/new-event-summary';
import { FormActions } from '@/components/pms/form-actions';
import { FormError } from '@/components/pms/form-error';
import { PageHeader } from '@/components/pms/page-header';
import { BookingGuestsSection } from '@/components/pms/reservations/booking-guests-section';
import {
    emptyGuest,
    guestsPayload,
} from '@/components/pms/reservations/booking-guests';
import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import { tabClass } from '@/components/pms/reservations/booking-tabs';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupText,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { create, index, quote as quoteRoute, store } from '@/routes/events';

type Props = {
    canManageGuests: boolean;
    /** Whether a new guest's ID may be typed (`guests.manage` and `guests.view-documents`). */
    canAddIdentity: boolean;
    identityDocumentTypes: EnumOption[];
    countries: EnumOption[];
    defaultNationalityCode: string;
    genders: EnumOption[];
    locales: EnumOption[];
    canViewCompany: boolean;
    companies: App.Data.CityLedger.CompanyOptionData[];
    /** Tentative and Confirmed. */
    statuses: EnumOption[];
    /** Both dates start here, `Y-m-d`: the business date, or `?date=` from Book facility on the Timeline. */
    defaultDate: string;
    /** `facilities.book`: the page shows facility lines. */
    canBook: boolean;
    facilities: App.Data.Facilities.FacilityOptionData[];
    /** `?facility=` from Book facility on the Timeline: the page starts with one line for it. */
    defaultFacilityId: number | null;
    currency: App.Data.Property.CurrencyData | null;
};

type PayerType = 'guest' | 'company';

type EventTab = 'facilities' | 'notes';

type Quote = App.Data.Facilities.EventLinesQuoteData;

type QuotePayload = {
    payer_guest_id: number | null;
    starts_on: string;
    ends_on: string;
    status: string;
    bookings: {
        facility_id: string | null;
        booking_date: string;
        starts_at: string;
        ends_at: string;
        quantity: string | number;
        unit_amount: string | null;
    }[];
};

const NOTES_LIMIT = 2000;

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="font-heading text-base font-semibold">
                    {title}
                </h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

/**
 * New event, in the layout of the booking form: the Event section (dates, status, name and payer), tabs for the
 * facility lines and the notes, and a summary rail. A guest payer is one guest row like the booker of the booking
 * form: search an existing guest or type a new one. Saving creates the guest, the event, its folio and its bookings
 * together and opens the event page; a refused line comes back as an error on that line. The server quotes the lines
 * 300 ms after a change. See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
 */
export default function EventCreate({
    canManageGuests,
    canAddIdentity,
    identityDocumentTypes,
    countries,
    defaultNationalityCode,
    genders,
    locales,
    canViewCompany,
    companies,
    statuses,
    defaultDate,
    canBook,
    facilities,
    defaultFacilityId,
    currency,
}: Props) {
    const startFacility = facilities.find(
        (facility) => facility.id === defaultFacilityId,
    );
    const form = useForm<{
        name: string;
        payerType: PayerType;
        guests: BookingGuest[];
        company: string;
        startsOn: string;
        endsOn: string;
        status: string;
        notes: string;
        bookings: FacilityLine[];
    }>({
        name: '',
        payerType: 'guest',
        guests: [emptyGuest('', defaultNationalityCode)],
        company: '',
        startsOn: defaultDate,
        endsOn: defaultDate,
        status: 'confirmed',
        notes: '',
        bookings: startFacility
            ? [
                  {
                      ...newFacilityLine(defaultDate),
                      facility_id: String(startFacility.id),
                      unit_amount: startFacility.listPrice ?? '',
                  },
              ]
            : [],
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const guard = useUnsavedChanges(form.isDirty && !form.processing);
    const [tab, setTab] = useState<EventTab>(canBook ? 'facilities' : 'notes');
    const payerRow = data.guests[0];

    const http = useHttp<QuotePayload, Quote>('post', quoteRoute.url(), {
        payer_guest_id: null,
        starts_on: '',
        ends_on: '',
        status: '',
        bookings: [],
    });
    const [quote, setQuote] = useState<Quote | null>(null);
    // An event that has started has happened, so it is Confirmed and its charges post. The server checks it too.
    const businessDate = usePage().props.businessDate?.date ?? null;
    const startedByBusinessDate =
        businessDate !== null &&
        data.startsOn !== '' &&
        data.startsOn <= businessDate;
    const status = startedByBusinessDate ? 'confirmed' : data.status;
    const payerGuestId =
        data.payerType === 'guest' ? (payerRow.guest?.id ?? null) : null;

    useEffect(() => {
        if (data.bookings.length === 0 || data.startsOn === '') {
            setQuote(null);

            return;
        }

        let current = true;
        const timer = window.setTimeout(() => {
            http.transform(() => ({
                payer_guest_id: payerGuestId,
                starts_on: data.startsOn,
                ends_on: data.endsOn || data.startsOn,
                status,
                bookings: data.bookings.map((line) => ({
                    facility_id:
                        line.facility_id === '' ? null : line.facility_id,
                    booking_date: line.booking_date || data.startsOn,
                    starts_at: line.starts_at,
                    ends_at: line.ends_at,
                    quantity: line.quantity === '' ? 1 : line.quantity,
                    unit_amount: chargedByHand(line, facilities)
                        ? null
                        : line.unit_amount,
                })),
            }));
            http.submit()
                .then((answer) => current && setQuote(answer))
                .catch(() => current && setQuote(null));
        }, 300);

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
        // The quote depends only on the lines, the dates, the status and the payer.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.bookings, data.startsOn, data.endsOn, status, payerGuestId]);

    const linesComplete = data.bookings.every(
        (line) =>
            line.facility_id !== '' &&
            line.booking_date !== '' &&
            line.starts_at !== '' &&
            line.ends_at !== '' &&
            line.quantity !== '',
    );
    const linesRefused = (quote?.lines ?? []).some(
        (line) => line.refusal !== null,
    );
    const payerMissing =
        data.payerType === 'company'
            ? data.company === ''
            : payerRow.guest === null && payerRow.full_name.trim() === '';

    const canSubmit =
        data.name.trim() !== '' &&
        !payerMissing &&
        data.startsOn !== '' &&
        data.endsOn !== '' &&
        linesComplete &&
        !linesRefused;

    const errorKeys = Object.keys(errors);
    const tabErrors: Record<EventTab, boolean> = {
        facilities: errorKeys.some((key) => key.startsWith('bookings')),
        notes: errorKeys.includes('notes'),
    };

    const submit = () => {
        form.transform((values) => {
            const [{ stays_in: _staysIn, ...guest }] = guestsPayload(
                values.guests,
            );

            return {
                name: values.name,
                payer_type: values.payerType,
                ...(values.payerType === 'guest'
                    ? { guest }
                    : { payer_company_id: values.company || null }),
                starts_on: values.startsOn,
                ends_on: values.endsOn || values.startsOn,
                status,
                notes: values.notes,
                ...(canBook
                    ? {
                          bookings: values.bookings.map((line) => ({
                              facility_id: line.facility_id,
                              booking_date: line.booking_date,
                              starts_at: line.starts_at,
                              ends_at: line.ends_at,
                              quantity: line.quantity,
                              unit_amount: chargedByHand(line, facilities)
                                  ? null
                                  : line.unit_amount || null,
                          })),
                      }
                    : {}),
            };
        });
        form.post(store.url());
    };

    return (
        <>
            <Head title="New event" />

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="flex min-h-0 flex-1 flex-col"
            >
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title="New event"
                        description="A bill for people who are not staying: a wedding dinner, a meeting or a walk-in lunch. It gets its own folio."
                        back={{ href: index(), label: 'Non-room reservations' }}
                    />

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <div className="flex flex-col gap-8">
                            <FormError errors={errors} />

                            <Section
                                title="Event"
                                description="Who pays and on which days. A one-day event has the same date at both ends."
                            >
                                <FieldGroup>
                                    <div className="grid items-start gap-4 lg:grid-cols-2">
                                        <Field data-invalid={!!errors.name}>
                                            <FieldLabel htmlFor="event-name">
                                                Event name
                                            </FieldLabel>
                                            <Input
                                                id="event-name"
                                                name="name"
                                                maxLength={150}
                                                placeholder="e.g. Lim & Tan wedding dinner"
                                                autoComplete="off"
                                                value={data.name}
                                                onChange={(change) =>
                                                    form.setData(
                                                        'name',
                                                        change.target.value,
                                                    )
                                                }
                                                aria-invalid={!!errors.name}
                                            />
                                            <FieldError>
                                                {errors.name}
                                            </FieldError>
                                        </Field>
                                        <div className="grid items-start gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                            <Field
                                                data-invalid={
                                                    !!errors.starts_on ||
                                                    !!errors.ends_on
                                                }
                                            >
                                                <FieldLabel htmlFor="event-dates">
                                                    Dates
                                                </FieldLabel>
                                                <DateRangePicker
                                                    id="event-dates"
                                                    from={data.startsOn}
                                                    to={data.endsOn}
                                                    onChange={(from, to) =>
                                                        form.setData(
                                                            (current) => ({
                                                                ...current,
                                                                startsOn: from,
                                                                endsOn:
                                                                    to || from,
                                                            }),
                                                        )
                                                    }
                                                    placeholder="e.g. 11/12/2026 → 12/12/2026"
                                                    aria-invalid={
                                                        !!errors.starts_on ||
                                                        !!errors.ends_on
                                                    }
                                                />
                                                <FieldError>
                                                    {errors.starts_on ??
                                                        errors.ends_on}
                                                </FieldError>
                                            </Field>
                                            <Field
                                                data-invalid={!!errors.status}
                                            >
                                                <FieldLabel htmlFor="event-status">
                                                    Status
                                                </FieldLabel>
                                                <EnumSelect
                                                    id="event-status"
                                                    name="status"
                                                    options={statuses}
                                                    value={status}
                                                    disabled={
                                                        startedByBusinessDate
                                                    }
                                                    onValueChange={(value) =>
                                                        form.setData(
                                                            'status',
                                                            value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.status
                                                    }
                                                />
                                                <FieldDescription>
                                                    {startedByBusinessDate
                                                        ? 'The event starts on or before the business date, so it is Confirmed and its charges post.'
                                                        : 'Tentative holds the dates before the deposit is paid.'}
                                                </FieldDescription>
                                                <FieldError>
                                                    {errors.status}
                                                </FieldError>
                                            </Field>
                                        </div>
                                    </div>

                                    {canViewCompany && (
                                        <ChoiceCards
                                            id="event-payer-type"
                                            legend="Payer"
                                            choices={[
                                                {
                                                    value: 'guest',
                                                    label: 'Guest',
                                                    description: canManageGuests
                                                        ? 'Search the guest list, or type a new name.'
                                                        : 'A guest already in the guest list.',
                                                    icon: User,
                                                },
                                                {
                                                    value: 'company',
                                                    label: 'Company',
                                                    description:
                                                        'Can settle to the city ledger.',
                                                    icon: Building2,
                                                },
                                            ]}
                                            value={data.payerType}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'payerType',
                                                    value as PayerType,
                                                )
                                            }
                                            error={errors.payer_type}
                                        />
                                    )}

                                    {data.payerType === 'company' ? (
                                        <Field
                                            data-invalid={
                                                !!errors.payer_company_id
                                            }
                                        >
                                            <FieldLabel htmlFor="event-company">
                                                Company
                                            </FieldLabel>
                                            <CompanySelect
                                                id="event-company"
                                                value={data.company}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'company',
                                                        value,
                                                    )
                                                }
                                                companies={companies}
                                                invalid={
                                                    !!errors.payer_company_id
                                                }
                                            />
                                            <FieldError>
                                                {errors.payer_company_id}
                                            </FieldError>
                                        </Field>
                                    ) : (
                                        <BookingGuestsSection
                                            single
                                            guests={data.guests}
                                            units={[]}
                                            canManageGuests={canManageGuests}
                                            canAddIdentity={canAddIdentity}
                                            identityDocumentTypes={
                                                identityDocumentTypes
                                            }
                                            countries={countries}
                                            defaultNationalityCode={
                                                defaultNationalityCode
                                            }
                                            genders={genders}
                                            locales={locales}
                                            errors={errors}
                                            onChange={(guests) =>
                                                form.setData('guests', guests)
                                            }
                                        />
                                    )}
                                </FieldGroup>
                            </Section>

                            <Tabs
                                value={tab}
                                onValueChange={(value) =>
                                    setTab(value as EventTab)
                                }
                            >
                                <TabsList
                                    variant="line"
                                    className="h-auto w-full justify-start border-b"
                                >
                                    {canBook && (
                                        <TabsTrigger
                                            value="facilities"
                                            className={tabClass(
                                                tabErrors.facilities,
                                            )}
                                        >
                                            <Tent data-icon="inline-start" />
                                            Facilities
                                            {data.bookings.length > 0 && (
                                                <Badge variant="secondary">
                                                    {data.bookings.length}
                                                </Badge>
                                            )}
                                        </TabsTrigger>
                                    )}
                                    <TabsTrigger
                                        value="notes"
                                        className={tabClass(tabErrors.notes)}
                                    >
                                        <NotebookText data-icon="inline-start" />
                                        Notes
                                    </TabsTrigger>
                                </TabsList>

                                {canBook && (
                                    <TabsContent
                                        value="facilities"
                                        keepMounted
                                        className="pt-4"
                                    >
                                        <Section
                                            title="Facility bookings"
                                            description={
                                                facilities.length === 0
                                                    ? 'No facilities are available. Add them in Settings, Facilities.'
                                                    : "Halls, equipment and activities for this event. They take the event's status and are charged to its folio."
                                            }
                                        >
                                            {facilities.length > 0 && (
                                                <EventFacilityLines
                                                    lines={data.bookings}
                                                    onChange={(bookings) =>
                                                        form.setData(
                                                            'bookings',
                                                            bookings,
                                                        )
                                                    }
                                                    facilities={facilities}
                                                    startsOn={data.startsOn}
                                                    endsOn={
                                                        data.endsOn ||
                                                        data.startsOn
                                                    }
                                                    quotes={
                                                        quote?.lines ?? null
                                                    }
                                                    errors={errors}
                                                    currency={currency}
                                                />
                                            )}
                                        </Section>
                                    </TabsContent>
                                )}

                                <TabsContent
                                    value="notes"
                                    keepMounted
                                    className="pt-4"
                                >
                                    <Field data-invalid={!!errors.notes}>
                                        <FieldLabel
                                            htmlFor="event-notes"
                                            className="sr-only"
                                        >
                                            Notes
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon
                                                align="block-start"
                                                className="border-b"
                                            >
                                                <NotebookText aria-hidden />
                                                <InputGroupText>
                                                    Menu, set-up or other
                                                    internal notes
                                                </InputGroupText>
                                            </InputGroupAddon>
                                            <InputGroupTextarea
                                                id="event-notes"
                                                name="notes"
                                                value={data.notes}
                                                onChange={(change) =>
                                                    form.setData(
                                                        'notes',
                                                        change.target.value,
                                                    )
                                                }
                                                maxLength={NOTES_LIMIT}
                                                rows={4}
                                                placeholder="e.g. Vegetarian menu for 20 guests"
                                                aria-invalid={!!errors.notes}
                                            />
                                            <InputGroupAddon
                                                align="block-end"
                                                className="justify-end"
                                            >
                                                <InputGroupText className="tabular-nums">
                                                    {data.notes.length}/
                                                    {NOTES_LIMIT}
                                                </InputGroupText>
                                            </InputGroupAddon>
                                        </InputGroup>
                                        <FieldError>{errors.notes}</FieldError>
                                    </Field>
                                </TabsContent>
                            </Tabs>
                        </div>

                        <aside className="h-fit xl:sticky xl:top-4">
                            <NewEventSummary
                                name={data.name}
                                startsOn={data.startsOn}
                                endsOn={data.endsOn || data.startsOn}
                                statusLabel={
                                    statuses.find((one) => one.value === status)
                                        ?.label ?? ''
                                }
                                lineCount={data.bookings.length}
                                quote={quote}
                                currency={currency}
                            />
                        </aside>
                    </div>
                </div>

                <FormActions fixed>
                    <Link
                        href={index()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <X data-icon="inline-start" />
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={!canSubmit || form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Plus data-icon="inline-start" />
                        )}
                        Create event
                    </Button>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name="this event"
                saving={form.processing}
                onSave={submit}
            />
        </>
    );
}

EventCreate.layout = (props: {
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    // The property code is passed in because this runs before AppLayout sets the URL defaults.
    breadcrumbs: [
        { title: 'Non-room reservations', href: index(props.property.code) },
        { title: 'New event', href: create(props.property.code) },
    ],
});
