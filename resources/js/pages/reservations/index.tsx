import { Head, router } from '@inertiajs/react';
import {
    Ban,
    CalendarDays,
    CircleCheck,
    ConciergeBell,
    Hourglass,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import {
    PaginationFooter,
    toPaginationData,
} from '@/components/pms/pagination-footer';
import { ReservationCard } from '@/components/pms/reservations/reservation-card';
import { ReservationFiltersBar } from '@/components/pms/reservations/reservation-filters';
import { ReservationList } from '@/components/pms/reservations/reservation-list';
import {
    hasActiveFilters,
    reservationQuery,
    resetFilters,
} from '@/components/pms/reservations/reservation-query';
import type {
    ReservationFilters,
    ReservationView,
} from '@/components/pms/reservations/reservation-query';
import { useReservationView } from '@/components/pms/reservations/use-reservation-view';
import { TabLinks } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/reservations';

type Item = App.Data.Reservations.ReservationListItemData;

type Props = {
    reservations: Illuminate.LengthAwarePaginator<number, Item>;
    statusCounts: App.Data.Reservations.ReservationStatusCountData[];
    filters: ReservationFilters;
    sort: string;
    sorts: EnumOption[];
    sources: EnumOption[];
    currency: App.Enums.Currency | null;
    hasReservations: boolean;
};

const statusIcons: Record<string, LucideIcon> = {
    '': CalendarDays,
    confirmed: CircleCheck,
    in_house: ConciergeBell,
    completed: Hourglass,
    cancelled: Ban,
};

export default function ReservationsIndex({
    reservations,
    statusCounts,
    filters,
    sort,
    sorts,
    sources,
    currency,
    hasReservations,
}: Props) {
    const [view, setView] = useReservationView();

    const visit = useCallback(
        (changes: {
            filters?: Partial<ReservationFilters>;
            sort?: string;
            reset?: boolean;
        }) => {
            router.get(
                index.url(undefined, {
                    query: reservationQuery({
                        filters: changes.reset
                            ? resetFilters(filters)
                            : { ...filters, ...changes.filters },
                        sort: changes.sort ?? sort,
                        view,
                    }),
                }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters, sort, view],
    );

    const tabHref = (status: string) =>
        index.url(undefined, {
            query: reservationQuery({
                filters: { ...filters, status },
                sort,
                view,
            }),
        });

    const tabs = statusCounts.map((tab) => ({
        value: tab.value === '' ? 'all' : tab.value,
        label: (
            <>
                {tab.label}
                <span className="text-muted-foreground tabular-nums">
                    {tab.count}
                </span>
            </>
        ),
        icon: statusIcons[tab.value] ?? CalendarDays,
        href: tabHref(tab.value),
    }));

    const pagination = toPaginationData(reservations.meta);
    const clearFilters = () =>
        visit({
            filters: {
                search: '',
                status: '',
                source: '',
                stay: { mode: 'default', from: null, to: null },
            },
        });
    const showAllDates = () =>
        visit({
            filters: { stay: { mode: 'all', from: null, to: null } },
        });
    const empty = hasActiveFilters(filters) ? (
        <EmptyState
            icon={CalendarDays}
            title="No reservations match these filters"
            action={
                <Button variant="outline" onClick={clearFilters}>
                    Clear filters
                </Button>
            }
        />
    ) : hasReservations ? (
        <EmptyState
            icon={CalendarDays}
            title="No upcoming reservations"
            description="Reservations that ended before the business date are hidden."
            action={
                <Button variant="outline" onClick={showAllDates}>
                    Show all dates
                </Button>
            }
        />
    ) : (
        <EmptyState
            icon={CalendarDays}
            title="No reservations yet"
            description="Bookings you take appear here."
        />
    );
    const showsDefaultHint =
        (filters.status === 'completed' || filters.status === 'cancelled') &&
        filters.stay.mode === 'default';
    const cards = (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {reservations.data.map((reservation) => (
                <ReservationCard
                    key={reservation.id}
                    reservation={reservation}
                    currency={currency}
                />
            ))}
        </div>
    );

    return (
        <>
            <Head title="Reservations" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader title="Reservations" />

                <TabLinks
                    tabs={tabs}
                    activeTab={filters.status === '' ? 'all' : filters.status}
                    panelWidth="full"
                    className="flex flex-col gap-4"
                    panelClassName="flex flex-col gap-4"
                >
                    <ReservationFiltersBar
                        filters={filters}
                        sort={sort}
                        sorts={sorts}
                        sources={sources}
                        view={view}
                        onChange={(changes) => visit({ filters: changes })}
                        onSortChange={(next) => visit({ sort: next })}
                        onViewChange={(next: ReservationView) => setView(next)}
                        onReset={() => visit({ reset: true })}
                    />

                    {showsDefaultHint && (
                        <p className="text-sm text-muted-foreground">
                            Showing stays from{' '}
                            <DateText date={filters.stay.from} />.{' '}
                            <Button
                                variant="link"
                                className="h-auto p-0"
                                onClick={showAllDates}
                            >
                                Show all dates
                            </Button>
                        </p>
                    )}

                    {view === 'list' ? (
                        <>
                            <div className="hidden lg:block">
                                <ReservationList
                                    reservations={reservations.data}
                                    currency={currency}
                                    sort={sort}
                                    onSortChange={(next) =>
                                        visit({ sort: next })
                                    }
                                    pagination={pagination}
                                    empty={empty}
                                />
                            </div>
                            <div className="flex flex-col gap-4 lg:hidden">
                                {reservations.data.length > 0 ? cards : empty}
                                <PaginationFooter pagination={pagination} />
                            </div>
                        </>
                    ) : (
                        <>
                            {reservations.data.length > 0 ? cards : empty}
                            <PaginationFooter pagination={pagination} />
                        </>
                    )}
                </TabLinks>
            </div>
        </>
    );
}

ReservationsIndex.layout = {
    breadcrumbs: [{ title: 'Reservations', href: index() }],
};
