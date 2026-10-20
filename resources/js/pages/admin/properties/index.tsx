import { Head, Link, router } from '@inertiajs/react';
import { Building2, Hourglass, List, Rocket } from 'lucide-react';
import { useCallback, useMemo } from 'react';
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
import { TabLinks } from '@/components/pms/tab-links';
import { Button, buttonVariants } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import { index, show, store } from '@/routes/admin/properties';
import { show as goLive } from '@/routes/admin/properties/go-live';
import { show as viewAs } from '@/routes/view-as';

type Row = App.Data.Platform.AdminPropertyRowData;
type Waiting = App.Data.Platform.AdminWaitingPropertyData;
type Board = App.Data.Platform.OnboardingBoardRowData;
type Filters = {
    search: string;
    status: string;
    type: string;
    partner: string;
};

type Props = {
    properties: Illuminate.LengthAwarePaginator<number, Row>;
    waiting: Record<string, Waiting>;
    onboarding: Record<string, Board>;
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
    waiting,
    onboarding,
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

    const waitingTab = filters.status === 'pending_approval';
    const onboardingTab = filters.status === 'onboarding';
    const tableColumns = useMemo(
        () =>
            onboardingTab
                ? [
                      ...columns,
                      column.display({
                          id: 'current_step',
                          header: 'Current step',
                          cell: (info) =>
                              onboarding[info.row.original.code]?.currentStep ??
                              '—',
                      }),
                      column.display({
                          id: 'progress',
                          header: 'Progress',
                          cell: (info) => (
                              <span className="tabular-nums">
                                  {onboarding[info.row.original.code]
                                      ?.percent ?? 0}
                                  %
                              </span>
                          ),
                      }),
                      column.display({
                          id: 'last_activity',
                          header: 'Last activity',
                          cell: (info) => (
                              <span className="tabular-nums">
                                  {onboarding[info.row.original.code]
                                      ?.lastActivity ?? '—'}
                              </span>
                          ),
                      }),
                      column.display({
                          id: 'view_as',
                          header: '',
                          cell: (info) => (
                              <Link
                                  href={viewAs(info.row.original.code, {
                                      query: { reason: 'onboarding_help' },
                                  })}
                                  className={buttonVariants({
                                      variant: 'outline',
                                      size: 'sm',
                                  })}
                                  onClick={(event) => event.stopPropagation()}
                              >
                                  View as
                                  <span className="sr-only">
                                      {' '}
                                      {info.row.original.name}
                                  </span>
                              </Link>
                          ),
                      }),
                  ]
                : waitingTab
                  ? [
                        ...columns,
                        column.display({
                            id: 'sent',
                            header: 'Sent',
                            cell: (info) =>
                                waiting[info.row.original.code]?.sentOn ?? '—',
                        }),
                        column.display({
                            id: 'warnings',
                            header: 'Warnings',
                            cell: (info) => (
                                <span className="tabular-nums">
                                    {waiting[info.row.original.code]
                                        ?.warningsCount ?? 0}
                                </span>
                            ),
                        }),
                        column.display({
                            id: 'review',
                            header: '',
                            cell: (info) => (
                                <Link
                                    href={goLive(info.row.original.code)}
                                    className={buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    })}
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    Review
                                    <span className="sr-only">
                                        {' '}
                                        {info.row.original.name}
                                    </span>
                                </Link>
                            ),
                        }),
                    ]
                  : columns,
        [onboardingTab, onboarding, waitingTab, waiting],
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

                <TabLinks
                    activeTab={
                        waitingTab
                            ? 'waiting'
                            : onboardingTab
                              ? 'onboarding'
                              : 'all'
                    }
                    panelWidth="full"
                    className="gap-4"
                    triggerClassName="min-w-0 px-0 mr-6"
                    panelClassName="flex flex-col gap-4"
                    tabs={[
                        {
                            value: 'all',
                            label: 'All',
                            icon: List,
                            href: index(),
                        },
                        {
                            value: 'onboarding',
                            label: 'Onboarding',
                            icon: Rocket,
                            href: index({
                                query: { filter: { status: 'onboarding' } },
                            }),
                        },
                        {
                            value: 'waiting',
                            label: 'Waiting for approval',
                            icon: Hourglass,
                            href: index({
                                query: {
                                    filter: { status: 'pending_approval' },
                                },
                            }),
                        },
                    ]}
                >
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
                        columns={tableColumns}
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
                </TabLinks>
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
