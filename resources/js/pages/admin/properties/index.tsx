import { Head, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useCallback } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { CreatePropertySheet } from '@/components/pms/properties/create-property-sheet';
import type { SetupMethod } from '@/components/pms/properties/create-property-sheet';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import { index, show, store } from '@/routes/admin/properties';

type Row = App.Data.Platform.AdminPropertyRowData;
type Filters = {
    search: string;
    status: string;
    type: string;
    partner: string;
};

type Props = {
    properties: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    sort: string;
    statuses: EnumOption[];
    types: EnumOption[];
    countries: EnumOption[];
    partners: EnumOption[];
    setupMethods: SetupMethod[];
    passwordRules: string;
};

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('code', {
        header: 'Code',
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('name', {
        header: 'Name',
        cell: (info) => <span className="font-medium">{info.getValue()}</span>,
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    column.accessor('type', {
        header: 'Type',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('city', {
        header: 'City',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('ownerName', {
        header: 'Owner',
        enableSorting: false,
        cell: (info) =>
            info.getValue() ? (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{info.getValue()}</span>
                    <span className="truncate text-xs text-muted-foreground">
                        {info.row.original.ownerEmail}
                    </span>
                </span>
            ) : (
                <span className="text-muted-foreground">No owner</span>
            ),
    }),
    column.accessor('partnerName', {
        header: 'Partner',
        enableSorting: false,
        cell: (info) =>
            info.getValue() ?? (
                <span className="text-muted-foreground">Direct</span>
            ),
    }),
    column.accessor('createdAt', {
        id: 'created_at',
        header: 'Created',
        cell: (info) => <DateText date={info.getValue()} />,
    }),
];

export default function PropertiesIndex({
    properties,
    filters,
    sort,
    statuses,
    types,
    countries,
    partners,
    setupMethods,
    passwordRules,
}: Props) {
    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                (['search', 'status', 'type', 'partner'] as const)
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

    const hasFilters = Boolean(
        filters.search || filters.status || filters.type || filters.partner,
    );

    return (
        <>
            <Head title="Properties" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Properties"
                    actions={
                        <CreatePropertySheet
                            form={store.form()}
                            types={types}
                            countries={countries}
                            setupMethods={setupMethods}
                            passwordRules={passwordRules}
                        />
                    }
                />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search code, name, city"
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
                        options={types}
                        value={filters.type}
                        onValueChange={(type) => visit({ type })}
                        allLabel="All types"
                        aria-label="Type"
                        className="w-48"
                    />
                    <EnumSelect
                        options={partners}
                        value={filters.partner}
                        onValueChange={(partner) => visit({ partner })}
                        allLabel="All partners"
                        aria-label="Partner"
                        className="w-48"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={properties.data}
                    getRowId={(row) => String(row.code)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    onRowClick={(row) => router.visit(show(row.code))}
                    pagination={{
                        currentPage: properties.meta.current_page,
                        lastPage: properties.meta.last_page,
                        from: properties.meta.from,
                        to: properties.meta.to,
                        total: properties.meta.total,
                        prevUrl: properties.meta.prev_page_url,
                        nextUrl: properties.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={Building2}
                                title="No properties match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                search: '',
                                                status: '',
                                                type: '',
                                                partner: '',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Building2}
                                title="No properties yet"
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

PropertiesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Properties', href: index() },
    ],
};
