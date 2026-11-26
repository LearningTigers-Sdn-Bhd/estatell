import { Head, Link, router } from '@inertiajs/react';
import { CalendarHeart, Plus } from 'lucide-react';
import { useCallback, useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { EmptyState } from '@/components/pms/empty-state';
import { eventDates } from '@/components/pms/events/event-shell';
import { FilterBar } from '@/components/pms/filter-bar';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { create, index, show } from '@/routes/events';

type Row = App.Data.Events.EventRowData;
type Filters = { search: string; status: string; period: string };

type Props = {
    events: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    statuses: EnumOption[];
    periods: EnumOption[];
    canManage: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

export default function EventsIndex({
    events,
    filters,
    statuses,
    periods,
    canManage,
    currency,
}: Props) {
    const columns = useMemo(
        () => [
            column.accessor('number', {
                header: 'No.',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium tabular-nums group-hover/row:underline">
                        {info.getValue()}
                    </span>
                ),
            }),
            column.accessor('name', { header: 'Event', enableSorting: false }),
            column.accessor('payerName', {
                header: 'Payer',
                enableSorting: false,
            }),
            column.accessor('startsOn', {
                header: 'Dates',
                enableSorting: false,
                cell: (info) => (
                    <span className="tabular-nums">
                        {eventDates(info.getValue(), info.row.original.endsOn)}
                    </span>
                ),
            }),
            column.accessor('status', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => <StatusBadge status={info.getValue()} />,
            }),
            column.accessor('balance', {
                header: currency ? `Balance (${currency.code})` : 'Balance',
                enableSorting: false,
                cell: (info) => (
                    <MoneyText
                        amount={info.getValue()}
                        currency={currency}
                        plain
                    />
                ),
            }),
        ],
        [currency],
    );

    const visit = useCallback(
        (changes: Partial<Filters>) => {
            const next = { ...filters, ...changes };
            const filter = Object.fromEntries(
                (['search', 'status', 'period'] as const)
                    .filter((key) => next[key] !== '')
                    .map((key) => [key, next[key]]),
            );

            router.get(
                index.url(undefined, {
                    query: Object.keys(filter).length ? { filter } : {},
                }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters],
    );

    const hasFilters = Boolean(
        filters.search || filters.status || filters.period !== 'all',
    );

    return (
        <>
            <Head title="Non-room reservations" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Non-room reservations"
                    description="Bills for people who are not staying: weddings, meetings, walk-in meals and activities."
                    actions={
                        canManage && (
                            <Link href={create()} className={buttonVariants()}>
                                <Plus data-icon="inline-start" />
                                New event
                            </Link>
                        )
                    }
                />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="e.g. Lim wedding"
                >
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-44"
                    />
                    <EnumSelect
                        options={periods}
                        value={filters.period}
                        onValueChange={(period) => visit({ period })}
                        aria-label="Date"
                        className="w-44"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={events.data}
                    getRowId={(row) => String(row.id)}
                    sort=""
                    onSortChange={() => undefined}
                    columnAlign={{ balance: 'right' }}
                    onRowClick={(row) =>
                        router.visit(show.url({ event: row.id }))
                    }
                    pagination={{
                        currentPage: events.meta.current_page,
                        lastPage: events.meta.last_page,
                        from: events.meta.from,
                        to: events.meta.to,
                        total: events.meta.total,
                        prevUrl: events.meta.prev_page_url,
                        nextUrl: events.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={CalendarHeart}
                                title="No events match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                search: '',
                                                status: '',
                                                period: 'all',
                                            })
                                        }
                                    >
                                        Show all events
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={CalendarHeart}
                                title="No events yet"
                                description="Create an event for a wedding, a meeting or a walk-in meal. It gets its own folio."
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

EventsIndex.layout = {
    breadcrumbs: [{ title: 'Non-room reservations', href: index() }],
};
