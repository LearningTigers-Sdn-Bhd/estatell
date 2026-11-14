import { Head, router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
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
import { index } from '@/routes/admin/sign-in-events';

type Row = App.Data.Platform.AdminSignInEventRowData;
type Filters = {
    search: string;
    result: string;
    property: string;
    from: string;
    to: string;
};

type Props = {
    events: Illuminate.LengthAwarePaginator<number, Row>;
    counts: Record<App.Enums.SignInResult, number>;
    filters: Filters;
    sort: string;
    results: EnumOption[];
    properties: EnumOption[];
};

const filterKeys = ['search', 'result', 'property', 'from', 'to'] as const;
const noFilters: Filters = {
    search: '',
    result: '',
    property: '',
    from: '',
    to: '',
};

const regionNames = new Intl.DisplayNames(['en'], { type: 'region' });

function countryName(code: string | null): string {
    if (!code) {
        return '—';
    }

    try {
        return regionNames.of(code) ?? code;
    } catch {
        return code;
    }
}

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('occurredAt', {
        id: 'occurred_at',
        header: 'Time (UTC)',
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('userName', {
        header: 'Account',
        enableSorting: false,
        cell: (info) => {
            const row = info.row.original;

            if (!row.userName) {
                return (
                    <span className="text-muted-foreground">
                        No account with that email
                    </span>
                );
            }

            return (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{row.userName}</span>
                    <span className="truncate text-xs text-muted-foreground">
                        {[row.userEmail, row.userWhere]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                </span>
            );
        },
    }),
    column.accessor('result', {
        header: 'Result',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    column.accessor('ipAddress', {
        header: 'IP address',
        enableSorting: false,
        cell: (info) => (
            <span className="font-mono text-xs">{info.getValue()}</span>
        ),
    }),
    column.accessor('countryCode', {
        header: 'Country',
        enableSorting: false,
        cell: (info) => countryName(info.getValue()),
    }),
    column.accessor('os', {
        header: 'Device',
        enableSorting: false,
        cell: (info) =>
            [info.getValue(), info.row.original.browser]
                .filter(Boolean)
                .join(' · ') || '—',
    }),
];

export default function SignInEventsIndex({
    events,
    counts,
    filters,
    sort,
    results,
    properties,
}: Props) {
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
                        ...(next.sort && next.sort !== '-occurred_at'
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

    const hasFilters = filterKeys.some((key) => filters[key] !== '');
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const notSignedIn = total - counts.succeeded;

    return (
        <>
            <Head title="Sign-in log" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Sign-in log"
                    description={`${total} sign-in attempts in the last 90 days. ${notSignedIn} failed, ${counts.locked_out} of them locked out.`}
                />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search IP address, email or name"
                >
                    <EnumSelect
                        options={results}
                        value={filters.result}
                        onValueChange={(result) => visit({ result })}
                        allLabel="All results"
                        aria-label="Result"
                        className="w-52"
                    />
                    <EnumSelect
                        options={properties}
                        value={filters.property}
                        onValueChange={(property) => visit({ property })}
                        allLabel="All properties"
                        aria-label="Property"
                        className="w-52"
                    />
                    <DateRangePicker
                        from={filters.from}
                        to={filters.to}
                        onChange={(from, to) => visit({ from, to })}
                        onClear={() => visit({ from: '', to: '' })}
                        clearLabel="All dates"
                        placeholder="All dates"
                        className="w-64"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={events.data}
                    getRowId={(row) => String(row.id)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
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
                                icon={Shield}
                                title="No sign-ins match these filters"
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
                            <EmptyState
                                icon={Shield}
                                title="No sign-ins recorded yet"
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

SignInEventsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Sign-in log', href: index() },
    ],
};
