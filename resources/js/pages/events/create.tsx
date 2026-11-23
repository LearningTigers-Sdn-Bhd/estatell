import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    EventFields,
    eventFieldPayload,
    payerMissing,
} from '@/components/pms/events/event-fields';
import type { EventFieldValues } from '@/components/pms/events/event-fields';
import {
    chargedByHand,
    EventFacilityLines,
} from '@/components/pms/events/event-facility-lines';
import type { FacilityLine } from '@/components/pms/events/event-facility-lines';
import { NewEventSummary } from '@/components/pms/events/new-event-summary';
import { FormActions } from '@/components/pms/form-actions';
import { FormError } from '@/components/pms/form-error';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { create, index, quote as quoteRoute, store } from '@/routes/events';

type Props = {
    canAddGuest: boolean;
    canViewCompany: boolean;
    companies: App.Data.CityLedger.CompanyOptionData[];
    /** Tentative and Confirmed. */
    statuses: EnumOption[];
    /** Both dates start here, `Y-m-d`: the business date. */
    defaultDate: string;
    /** `facilities.book`: the page shows facility lines. */
    canBook: boolean;
    facilities: App.Data.Facilities.FacilityOptionData[];
    currency: App.Data.Property.CurrencyData | null;
};

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

/**
 * New event: the event, its facility lines and a summary rail. Saving creates the event, its folio and its bookings
 * together and opens the event page; a refused line comes back as an error on that line. The server quotes the lines
 * 300 ms after a change. See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
 */
export default function EventCreate({
    canAddGuest,
    canViewCompany,
    companies,
    statuses,
    defaultDate,
    canBook,
    facilities,
    currency,
}: Props) {
    const form = useForm<
        EventFieldValues & {
            status: string;
            notes: string;
            bookings: FacilityLine[];
        }
    >({
        name: '',
        payerType: 'guest',
        payer: null,
        newGuestName: '',
        company: '',
        startsOn: defaultDate,
        endsOn: defaultDate,
        status: 'confirmed',
        notes: '',
        bookings: [],
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const guard = useUnsavedChanges(form.isDirty && !form.processing);

    const http = useHttp<QuotePayload, Quote>('post', quoteRoute.url(), {
        payer_guest_id: null,
        starts_on: '',
        ends_on: '',
        status: '',
        bookings: [],
    });
    const [quote, setQuote] = useState<Quote | null>(null);
    const payerGuestId =
        data.payerType === 'guest' ? (data.payer?.id ?? null) : null;

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
                status: data.status,
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
    }, [data.bookings, data.startsOn, data.endsOn, data.status, payerGuestId]);

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

    const canSubmit =
        data.name.trim() !== '' &&
        !payerMissing(data) &&
        data.startsOn !== '' &&
        data.endsOn !== '' &&
        linesComplete &&
        !linesRefused;

    const submit = () => {
        form.transform((values) => ({
            ...eventFieldPayload(values),
            status: values.status,
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
        }));
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
                        back={{ href: index(), label: 'Events' }}
                    />

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <div className="flex flex-col gap-8">
                            <FormError errors={errors} />

                            <FieldSet className="max-w-2xl">
                                <FieldLegend>Event</FieldLegend>
                                <FieldDescription>
                                    Who pays and on which days. A one-day event
                                    has the same date at both ends.
                                </FieldDescription>
                                <FieldGroup>
                                    <EventFields
                                        values={data}
                                        onChange={(changes) =>
                                            form.setData((current) => ({
                                                ...current,
                                                ...changes,
                                            }))
                                        }
                                        errors={errors}
                                        isNew
                                        canAddGuest={canAddGuest}
                                        canViewCompany={canViewCompany}
                                        companies={companies}
                                    />
                                    <Field data-invalid={!!errors.status}>
                                        <FieldLabel htmlFor="event-status">
                                            Status
                                        </FieldLabel>
                                        <EnumSelect
                                            id="event-status"
                                            name="status"
                                            options={statuses}
                                            value={data.status}
                                            onValueChange={(value) =>
                                                form.setData('status', value)
                                            }
                                            aria-invalid={!!errors.status}
                                        />
                                        <FieldDescription>
                                            Tentative holds the dates before the
                                            deposit is paid.
                                        </FieldDescription>
                                        <FieldError>{errors.status}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!errors.notes}>
                                        <FieldLabel htmlFor="event-notes">
                                            Notes
                                        </FieldLabel>
                                        <Textarea
                                            id="event-notes"
                                            name="notes"
                                            maxLength={2000}
                                            placeholder="e.g. Vegetarian menu for 20 guests"
                                            value={data.notes}
                                            onChange={(change) =>
                                                form.setData(
                                                    'notes',
                                                    change.target.value,
                                                )
                                            }
                                            aria-invalid={!!errors.notes}
                                        />
                                        <FieldError>{errors.notes}</FieldError>
                                    </Field>
                                </FieldGroup>
                            </FieldSet>

                            {canBook && (
                                <FieldSet>
                                    <FieldLegend>Facility bookings</FieldLegend>
                                    <FieldDescription>
                                        {facilities.length === 0
                                            ? 'No facilities are available. Add them in Settings, Facilities.'
                                            : "Halls, equipment and activities for this event. They take the event's status and are charged to its folio."}
                                    </FieldDescription>
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
                                                data.endsOn || data.startsOn
                                            }
                                            quotes={quote?.lines ?? null}
                                            errors={errors}
                                            currency={currency}
                                        />
                                    )}
                                </FieldSet>
                            )}
                        </div>

                        <aside className="h-fit xl:sticky xl:top-4">
                            <NewEventSummary
                                name={data.name}
                                startsOn={data.startsOn}
                                endsOn={data.endsOn || data.startsOn}
                                statusLabel={
                                    statuses.find(
                                        (one) => one.value === data.status,
                                    )?.label ?? ''
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
        { title: 'Events', href: index(props.property.code) },
        { title: 'New event', href: create(props.property.code) },
    ],
});
