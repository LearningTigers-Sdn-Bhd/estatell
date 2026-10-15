import { Head, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { activityLog } from '@/routes/reports';

type Row = App.Data.Reports.ActivityLogRowData;

type Props = {
    entries: Illuminate.LengthAwarePaginator<number, Row>;
    search: string;
    sort: string;
};

const column = dataTableColumns<Row>();
const columns = [
    column.accessor('createdAt', {
        id: 'created_at',
        header: 'Time',
        cell: (info) => (
            <time dateTime={info.getValue()} className="tabular-nums">
                {new Date(info.getValue()).toLocaleString('en-GB', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                })}
            </time>
        ),
    }),
    column.accessor('causerName', {
        header: 'Recorded by',
        enableSorting: false,
    }),
    column.accessor('description', {
        header: 'Action',
        enableSorting: false,
    }),
    column.accessor('subject', {
        header: 'Record',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('event', {
        header: 'Event',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
];

export default function ActivityLog({ entries, search, sort }: Props) {
    function visit(changes: { search?: string; sort?: string }) {
        const next = { search, sort, ...changes };

        router.get(
            activityLog.url(undefined, {
                query: {
                    ...(next.search ? { filter: { search: next.search } } : {}),
                    ...(next.sort !== '-created_at' ? { sort: next.sort } : {}),
                },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Activity log" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Activity log"
                    description="Recorded actions for this property. Times use your browser's time zone."
                />
                <FilterBar
                    search={search}
                    onSearchChange={(value) => visit({ search: value })}
                    searchPlaceholder="Search actions or events"
                />
                <DataTable
                    columns={columns}
                    data={entries.data}
                    getRowId={(row) => String(row.id)}
                    sort={sort}
                    onSortChange={(value) => visit({ sort: value })}
                    pagination={{
                        currentPage: entries.meta.current_page,
                        lastPage: entries.meta.last_page,
                        from: entries.meta.from,
                        to: entries.meta.to,
                        total: entries.meta.total,
                        prevUrl: entries.meta.prev_page_url,
                        nextUrl: entries.meta.next_page_url,
                    }}
                    empty={
                        <EmptyState
                            icon={History}
                            title={
                                search
                                    ? 'No activity matches your search'
                                    : 'No activity recorded yet'
                            }
                            description={
                                search
                                    ? 'Try another action or event.'
                                    : 'Recorded property actions will appear here.'
                            }
                        />
                    }
                />
            </div>
        </>
    );
}

ActivityLog.layout = {
    breadcrumbs: [{ title: 'Activity log', href: activityLog() }],
};
