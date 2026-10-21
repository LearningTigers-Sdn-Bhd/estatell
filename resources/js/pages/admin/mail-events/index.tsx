import { Head, router } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { useCallback } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/mail-events';

type Row = App.Data.Platform.AdminMailEventRowData;
type Filters = {
    search: string;
    status: string;
    from: string;
    to: string;
};

type Props = {
    events: Illuminate.LengthAwarePaginator<number, Row>;
    counts: Record<string, number>;
    filters: Filters;
    sort: string;
    statuses: EnumOption[];
};

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('sentAt', {
        id: 'sent_at',
        header: 'Sent',
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('recipient', {
        header: 'Recipient',
        enableSorting: false,
        cell: (info) => (
            <span className="flex min-w-0 flex-col">
                <span className="truncate">{info.getValue()}</span>
                {info.row.original.recipientCount > 1 && (
                    <span className="text-xs text-muted-foreground">
                        +{info.row.original.recipientCount - 1} more
                    </span>
                )}
            </span>
        ),
    }),
    column.accessor('subject', {
        header: 'Subject',
        enableSorting: false,
        cell: (info) => (
            <span className="block max-w-96 truncate">{info.getValue()}</span>
        ),
    }),
    column.accessor('notificationType', {
        header: 'Type',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
];

export default function MailEventsIndex({
    events,
    counts,
    filters,
    sort,
    statuses,
}: Props) {
    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                (['search', 'status', 'from', 'to'] as const)
                    .filter((key) => next[key] !== '')
                    .map((key) => [key, next[key]]),
            );

            router.get(
                index.url({
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(next.sort && next.sort !== '-sent_at'
                            ? { sort: next.sort }
                            : {}),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters, sort],
    );

    const hasFilters = Boolean(
        filters.search || filters.status || filters.from || filters.to,
    );
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);

    return (
        <>
            <Head title="Mail log" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Mail log"
                    description={`${total} emails in the last 90 days. ${(counts.failed ?? 0) + (counts.bounced ?? 0)} failed or bounced, ${counts.suppressed ?? 0} suppressed.`}
                />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search email address or subject"
                >
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-44"
                    />
                    <DateRangePicker
                        from={filters.from}
                        to={filters.to}
                        onChange={(from, to) => visit({ from, to })}
                        onClear={() => visit({ from: '', to: '' })}
                        clearLabel="All dates"
                        placeholder="All dates"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={events.data}
                    getRowId={(row) => String(row.id)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    onRowClick={(row) => router.visit(show(row.id))}
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
                                icon={Mail}
                                title="No emails match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                search: '',
                                                status: '',
                                                from: '',
                                                to: '',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Mail}
                                title="No emails sent yet"
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

MailEventsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Mail log', href: index() },
    ],
};
