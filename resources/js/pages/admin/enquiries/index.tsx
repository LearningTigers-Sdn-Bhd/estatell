import { Head, Link, router } from '@inertiajs/react';
import { Ellipsis, Inbox, Trash2 } from 'lucide-react';
import { useCallback, useState } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DeleteEnquiryDialog } from '@/components/pms/delete-enquiry-dialog';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { update } from '@/routes/admin/enquiries/status';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/enquiries';

type Row = App.Data.Platform.AdminContactEnquiryRowData;
type Filters = { search: string; status: string };

type Props = {
    enquiries: Illuminate.LengthAwarePaginator<number, Row>;
    counts: Record<App.Enums.ContactEnquiryStatus, number>;
    filters: Filters;
    sort: string;
    statuses: EnumOption[];
};

const filterKeys = ['search', 'status'] as const;
const noFilters: Filters = { search: '', status: '' };

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('receivedAt', {
        id: 'created_at',
        header: 'Received',
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('hotelName', {
        header: 'Hotel',
        enableSorting: false,
        cell: (info) => (
            <span className="flex min-w-0 flex-col">
                <span className="truncate font-medium">{info.getValue()}</span>
                <span className="truncate text-xs text-muted-foreground">
                    {info.row.original.location}
                </span>
            </span>
        ),
    }),
    column.accessor('name', {
        header: 'Contact',
        enableSorting: false,
        cell: (info) => (
            <span className="flex min-w-0 flex-col">
                <span className="truncate">{info.getValue()}</span>
                <span className="truncate text-xs text-muted-foreground">
                    {info.row.original.email}
                </span>
            </span>
        ),
    }),
    column.accessor('roomCount', {
        header: 'Rooms',
        enableSorting: false,
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
];

export default function EnquiriesIndex({
    enquiries,
    counts,
    filters,
    sort,
    statuses,
}: Props) {
    const [deleting, setDeleting] = useState<Row | null>(null);
    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                filterKeys
                    .filter((key) => next[key] !== '')
                    .map((key) => [key, next[key]]),
            );

            router.get(
                index.url({
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(next.sort && next.sort !== '-created_at'
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

    const tableColumns = [
        ...columns,
        column.display({
            id: 'actions',
            header: 'Actions',
            cell: ({ row: { original: enquiry } }) => (
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={<Button variant="ghost" size="icon-sm" />}
                        aria-label={`Actions for ${enquiry.hotelName}`}
                        onClick={(event) => event.stopPropagation()}
                    >
                        <Ellipsis />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        className="w-48"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <DropdownMenuItem
                            render={<Link href={show(enquiry.id)} />}
                        >
                            View enquiry
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {statuses
                            .filter(
                                (status) =>
                                    status.value !== enquiry.status.value,
                            )
                            .map((status) => (
                                <DropdownMenuItem
                                    key={status.value}
                                    onClick={() =>
                                        router.put(
                                            update.url(enquiry.id),
                                            { status: status.value },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Mark {status.label.toLowerCase()}
                                </DropdownMenuItem>
                            ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={() => setDeleting(enquiry)}
                        >
                            <Trash2 />
                            Delete enquiry
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        }),
    ];

    const hasFilters = filterKeys.some((key) => filters[key] !== '');
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);

    return (
        <>
            <Head title="Enquiries" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Enquiries"
                    description={`${total} from the Contact Us form. ${counts.new} new.`}
                />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search hotel, name, email or location"
                >
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-52"
                    />
                </FilterBar>

                <DataTable
                    columns={tableColumns}
                    columnAlign={{ actions: 'right' }}
                    onRowClick={(row) => router.visit(show(row.id))}
                    data={enquiries.data}
                    getRowId={(row) => String(row.id)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    pagination={{
                        currentPage: enquiries.meta.current_page,
                        lastPage: enquiries.meta.last_page,
                        from: enquiries.meta.from,
                        to: enquiries.meta.to,
                        total: enquiries.meta.total,
                        prevUrl: enquiries.meta.prev_page_url,
                        nextUrl: enquiries.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={Inbox}
                                title="No enquiries match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() => visit(noFilters)}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState icon={Inbox} title="No enquiries yet" />
                        )
                    }
                />
            </div>
            <DeleteEnquiryDialog
                enquiry={deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            />
        </>
    );
}

EnquiriesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Enquiries', href: index() },
    ],
};
