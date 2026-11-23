import { Link, useHttp } from '@inertiajs/react';
import { BedDouble, CalendarDays } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { buttonVariants } from '@/components/ui/button';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { create as newEvent } from '@/routes/events';
import {
    events as ownerEvents,
    reservation as ownerReservation,
    reservations as ownerReservations,
} from '@/routes/facility-bookings/owners';
import { BookFacilitySheet } from './book-facility-sheet';
import type { BookingOwner } from './book-facility-sheet';
import { forStepReady } from './timeline-book-facility-step';
import type { OwnerType } from './timeline-book-facility-step';

type Reservation = App.Data.Facilities.FacilityOwnerReservationData;
type OwnerEvent = App.Data.Facilities.FacilityOwnerEventData;
type Facility = App.Data.Facilities.FacilityOptionData;

/** What the reservation's Facilities tab sends for the Sheet, read here when a reservation is picked. */
type ReservationSheet = {
    facilities: Facility[];
    chargeToOptions: App.Data.Facilities.ChargeToOptionData[];
    defaultChargeTo: Record<string, string | null>;
    facilityDefaultDate: string;
};

type EventsAnswer = { events: OwnerEvent[]; facilities: Facility[] };

/** The facility and date the Timeline's day peek asked to book. */
export type TimelineBookingCell = {
    facilityId: number;
    facilityName: string;
    date: string;
};

type Picked = {
    owner: BookingOwner;
    label: string;
    facilities: Facility[];
} | null;

const SEARCH_MIN_LENGTH = 2;

function dayLabel(date: string): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
}

/**
 * Live reservations that match the text, asked for after a pause in typing. A slow answer to an older text never
 * replaces a newer one. Nothing is asked for under two characters.
 */
function useReservationSearch(text: string): {
    results: Reservation[];
    searching: boolean;
} {
    const [results, setResults] = useState<Reservation[]>([]);
    const [searching, setSearching] = useState(false);
    const latest = useRef(0);
    const query = text.trim();

    useEffect(() => {
        const request = ++latest.current;

        if (query.length < SEARCH_MIN_LENGTH) {
            setResults([]);
            setSearching(false);

            return;
        }

        setSearching(true);

        const timeout = setTimeout(async () => {
            try {
                const response = await fetch(
                    ownerReservations.url({}, { query: { q: query } }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                const body = (await response.json()) as Reservation[];

                if (request === latest.current) {
                    setResults(body);
                }
            } catch {
                if (request === latest.current) {
                    setResults([]);
                }
            } finally {
                if (request === latest.current) {
                    setSearching(false);
                }
            }
        }, 300);

        return () => clearTimeout(timeout);
    }, [query]);

    return { results, searching };
}

function ReservationSearch({
    value,
    onChange,
}: {
    value: Reservation | null;
    onChange: (reservation: Reservation | null) => void;
}) {
    const [query, setQuery] = useState('');
    const { results, searching } = useReservationSearch(query);
    const items =
        value && !results.some((one) => one.id === value.id)
            ? [...results, value]
            : results;

    return (
        <Combobox
            items={items}
            value={value}
            onValueChange={(next) => onChange(next as Reservation | null)}
            onInputValueChange={(next, details) => {
                if (details.reason !== 'item-press') {
                    setQuery(next);
                }
            }}
            itemToStringLabel={(one: Reservation) => one.label}
            isItemEqualToValue={(item: Reservation, selected: Reservation) =>
                item.id === selected.id
            }
            filter={null}
        >
            <ComboboxInput
                id="book-for-reservation"
                className="w-full"
                placeholder="Search guest, reference or room"
                autoComplete="off"
                showClear
            />
            <ComboboxContent>
                <ComboboxEmpty>
                    {searching
                        ? 'Searching…'
                        : query.trim().length < SEARCH_MIN_LENGTH
                          ? 'Type at least 2 letters to search.'
                          : 'No live reservations found.'}
                </ComboboxEmpty>
                <ComboboxList>
                    {(one: Reservation) => (
                        <ComboboxItem key={one.id} value={one}>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate">{one.label}</span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {one.detail}
                                </span>
                            </span>
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}

/**
 * Book facility from the Timeline's day peek. The For step picks a live reservation (searched) or an event on the
 * date (listed); **+ New event** opens the New event page with the date and facility filled in. Next opens the
 * Book facility Sheet with the facility, date and owner filled in, and its **Change** comes back here. The Sheet
 * saves through `facility-bookings.store`, which checks again. A new third-party folio is not offered here: staff
 * make it on the reservation's Facilities tab. See docs/33-facilities/05-timeline-and-report.md.
 */
export function TimelineBookFacility({
    cell,
    onClose,
    canViewReservations,
    canViewEvents,
    canManageEvents,
    currency,
}: {
    cell: TimelineBookingCell | null;
    onClose: () => void;
    canViewReservations: boolean;
    canViewEvents: boolean;
    canManageEvents: boolean;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [type, setType] = useState<OwnerType>(
        canViewReservations ? 'reservation' : 'event',
    );
    const [reservation, setReservation] = useState<Reservation | null>(null);
    const [eventId, setEventId] = useState('');
    const [events, setEvents] = useState<EventsAnswer | null>(null);
    const [picked, setPicked] = useState<Picked>(null);
    const [failed, setFailed] = useState(false);
    const sheetHttp = useHttp<Record<string, never>, ReservationSheet>(
        'get',
        '',
        {},
    );
    const eventsHttp = useHttp<Record<string, never>, EventsAnswer>(
        'get',
        '',
        {},
    );

    // A new cell starts the For step again.
    useEffect(() => {
        setReservation(null);
        setEventId('');
        setEvents(null);
        setPicked(null);
        setFailed(false);
    }, [cell?.facilityId, cell?.date]);

    // The events on the date load when the Event choice shows.
    useEffect(() => {
        if (cell === null || type !== 'event' || events !== null) {
            return;
        }

        let current = true;

        eventsHttp
            .get(ownerEvents.url({}, { query: { date: cell.date } }))
            .then((answer) => current && setEvents(answer))
            .catch(() => current && setFailed(true));

        return () => {
            current = false;
        };
        // The list depends only on the date and the choice.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cell?.date, type, events]);

    if (cell === null) {
        return null;
    }

    const chosenEvent = events?.events.find(
        (one) => String(one.id) === eventId,
    );

    const next = () => {
        setFailed(false);

        if (type === 'event' && chosenEvent && events) {
            setPicked({
                owner: {
                    type: 'event',
                    id: chosenEvent.id,
                    startsOn: chosenEvent.startsOn,
                    endsOn: chosenEvent.endsOn,
                    chargeTo: `Event folio · ${chosenEvent.payerName}`,
                },
                label: `${chosenEvent.name} · ${chosenEvent.number}`,
                facilities: events.facilities,
            });

            return;
        }

        if (type === 'reservation' && reservation) {
            sheetHttp
                .get(ownerReservation.url({ reservation: reservation.id }))
                .then((sheet) =>
                    setPicked({
                        owner: {
                            type: 'reservation',
                            id: reservation.id,
                            defaultDate: sheet.facilityDefaultDate,
                            chargeToOptions: sheet.chargeToOptions,
                            defaultChargeTo: sheet.defaultChargeTo,
                            canCreateFolio: false,
                            canViewCompany: false,
                        },
                        label: reservation.label,
                        facilities: sheet.facilities,
                    }),
                )
                .catch(() => setFailed(true));
        }
    };

    if (picked !== null) {
        return (
            <BookFacilitySheet
                open
                onOpenChange={(open) => !open && onClose()}
                owner={picked.owner}
                facilities={picked.facilities}
                currency={currency}
                initialFacilityId={cell.facilityId}
                initialDate={cell.date}
                ownerLabel={picked.label}
                onChangeOwner={() => setPicked(null)}
            />
        );
    }

    const choices = [
        ...(canViewReservations
            ? [
                  {
                      value: 'reservation',
                      label: 'Reservation',
                      description: 'A staying guest.',
                      icon: BedDouble,
                  },
              ]
            : []),
        ...(canViewEvents
            ? [
                  {
                      value: 'event',
                      label: 'Event',
                      description: 'A group or a non-staying guest.',
                      icon: CalendarDays,
                  },
              ]
            : []),
    ];

    return (
        <FormOverlay
            variant="sheet"
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Book ${cell.facilityName}`}
            description={`${dayLabel(cell.date)}. Who is the booking for?`}
            errors={{}}
            onSubmit={next}
            submitLabel="Next"
            processing={sheetHttp.processing}
            submitDisabled={
                !forStepReady(type, reservation?.id ?? null, eventId)
            }
        >
            {choices.length > 1 && (
                <ChoiceCards
                    id="book-for-type"
                    legend="For"
                    choices={choices}
                    value={type}
                    onValueChange={(value) => setType(value as OwnerType)}
                />
            )}
            {type === 'reservation' ? (
                <Field data-invalid={failed}>
                    <FieldLabel htmlFor="book-for-reservation">
                        Reservation
                    </FieldLabel>
                    <ReservationSearch
                        value={reservation}
                        onChange={setReservation}
                    />
                    {failed && (
                        <FieldError>
                            Could not load this reservation. Try again.
                        </FieldError>
                    )}
                </Field>
            ) : (
                <Field data-invalid={failed}>
                    <FieldLabel htmlFor="book-for-event">Event</FieldLabel>
                    {events !== null && events.events.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No events on this date.
                        </p>
                    ) : (
                        <EnumSelect
                            id="book-for-event"
                            name="event_id"
                            className="w-full"
                            options={(events?.events ?? []).map((one) => ({
                                value: String(one.id),
                                label: one.name,
                                description: `${one.number} · ${one.payerName}`,
                            }))}
                            value={eventId}
                            onValueChange={setEventId}
                            placeholder={
                                events === null
                                    ? 'Loading events…'
                                    : 'Choose an event'
                            }
                            disabled={events === null}
                        />
                    )}
                    {failed && (
                        <FieldError>
                            Could not load the events. Close and try again.
                        </FieldError>
                    )}
                    {canManageEvents && (
                        <Link
                            href={newEvent.url(
                                {},
                                {
                                    query: {
                                        date: cell.date,
                                        facility: cell.facilityId,
                                    },
                                },
                            )}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                                className: 'self-start',
                            })}
                        >
                            + New event
                        </Link>
                    )}
                </Field>
            )}
        </FormOverlay>
    );
}
