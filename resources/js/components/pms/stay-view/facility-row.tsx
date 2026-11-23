import { useHttp } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { show as eventPage } from '@/routes/events';
import { day as facilityDay } from '@/routes/front-desk/facilities';
import { facilities as reservationFacilities } from '@/routes/reservations';
import type { FacilityCellView } from './facility-rows';

type Facility = App.Data.Facilities.FacilityTimelineRowData;
type Line = App.Data.Facilities.FacilityDayLineData;

type DayState =
    | { status: 'loading' }
    | { status: 'ready'; lines: Line[] }
    | { status: 'failed' };

/** The label of a facility row: its name and its How many. */
export function FacilityLabel({ facility }: { facility: Facility }) {
    return (
        <span className="flex min-w-0 flex-col">
            <span className="truncate text-sm">{facility.name}</span>
            <span className="truncate text-xs text-grid-muted-foreground tabular-nums">
                {facility.single
                    ? '1 item'
                    : `${facility.capacity} ${facility.unit}`}
            </span>
        </span>
    );
}

function dayLabel(date: string): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
}

/**
 * One facility on one date. A click opens the day peek, which loads that day's bookings when it opens, and offers
 * **+ Book facility** to staff with `facilities.book`.
 */
export function FacilityDayCell({
    facility,
    date,
    view,
    hasBookings,
    canViewReservations,
    canViewEvents,
    onBook,
}: {
    facility: Facility;
    date: string;
    view: FacilityCellView;
    hasBookings: boolean;
    canViewReservations: boolean;
    canViewEvents: boolean;
    /** Shows **+ Book facility**, which closes the peek and opens the Book facility Sheet. */
    onBook?: (facility: Facility, date: string) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <button
                        type="button"
                        title={view.title}
                        aria-label={`${facility.name}, ${dayLabel(date)}: ${view.title}`}
                        className={cn(
                            'absolute inset-0 flex items-center justify-center truncate px-1 text-xs tabular-nums outline-none hover:bg-grid-hover focus-visible:ring-3 focus-visible:ring-ring/50',
                            facility.single &&
                                view.text !== '' &&
                                'justify-start border-l-4 border-primary bg-primary/10 text-left font-medium',
                            !hasBookings && 'text-grid-muted-foreground',
                            view.full && 'font-semibold text-warning',
                            view.tentative &&
                                'border-border bg-[repeating-linear-gradient(135deg,transparent_0_6px,var(--color-border)_6px_12px)] text-muted-foreground',
                        )}
                    />
                }
            >
                {view.text}
            </PopoverTrigger>
            <PopoverContent className="w-80" align="start">
                <PopoverHeader>
                    <PopoverTitle>
                        {facility.name} · {dayLabel(date)}
                    </PopoverTitle>
                </PopoverHeader>
                {open && (
                    <FacilityDayLines
                        facilityId={facility.id}
                        date={date}
                        hasBookings={hasBookings}
                        canViewReservations={canViewReservations}
                        canViewEvents={canViewEvents}
                    />
                )}
                {!facility.single && (
                    <p className="text-xs text-muted-foreground">
                        {view.title}.
                    </p>
                )}
                {onBook && (
                    <Button
                        type="button"
                        size="sm"
                        className="self-end"
                        onClick={() => {
                            setOpen(false);
                            onBook(facility, date);
                        }}
                    >
                        + Book facility
                    </Button>
                )}
            </PopoverContent>
        </Popover>
    );
}

function FacilityDayLines({
    facilityId,
    date,
    hasBookings,
    canViewReservations,
    canViewEvents,
}: {
    facilityId: number;
    date: string;
    hasBookings: boolean;
    canViewReservations: boolean;
    canViewEvents: boolean;
}) {
    const url = facilityDay.url({ facility: facilityId, date });
    const http = useHttp<Record<string, never>, Line[]>('get', url, {});
    const [state, setState] = useState<DayState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!hasBookings) {
            setState({ status: 'ready', lines: [] });

            return;
        }

        let current = true;

        setState({ status: 'loading' });
        http.submit()
            .then((lines) => current && setState({ status: 'ready', lines }))
            .catch(() => current && setState({ status: 'failed' }));

        return () => {
            current = false;
        };
        // The request depends only on the url and the attempt.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url, hasBookings, attempt]);

    if (state.status === 'loading') {
        return <Skeleton aria-busy="true" className="h-16 w-full rounded-lg" />;
    }

    if (state.status === 'failed') {
        return (
            <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                Could not load the bookings.
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setAttempt((count) => count + 1)}
                >
                    Try again
                </Button>
            </div>
        );
    }

    if (state.lines.length === 0) {
        return <p className="text-sm text-muted-foreground">No bookings.</p>;
    }

    return (
        <ul className="flex flex-col gap-1">
            {state.lines.map((line) => {
                const href =
                    line.eventId !== null
                        ? canViewEvents
                            ? eventPage.url({ event: line.eventId })
                            : null
                        : line.reservationId !== null && canViewReservations
                          ? reservationFacilities.url({
                                reservation: line.reservationId,
                            })
                          : null;
                const body = (
                    <>
                        <span className="w-24 shrink-0 text-xs text-muted-foreground tabular-nums">
                            {line.startsAt}–{line.endsAt}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate">{line.name}</span>
                            <span className="text-xs text-muted-foreground">
                                {line.eventId !== null ? 'Event' : 'Booking'} ·{' '}
                                {line.quantity} {line.quantityUnit}
                            </span>
                        </span>
                        {line.status.value === 'tentative' && (
                            <StatusBadge status={line.status} />
                        )}
                        {href && (
                            <ChevronRight
                                aria-hidden
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                        )}
                    </>
                );

                return (
                    <li key={line.id}>
                        {href ? (
                            <Link
                                href={href}
                                className="flex items-center gap-2 rounded-md p-1.5 text-sm outline-none hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                {body}
                            </Link>
                        ) : (
                            <div className="flex items-center gap-2 p-1.5 text-sm">
                                {body}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
