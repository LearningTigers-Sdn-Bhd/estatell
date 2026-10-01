import { Head, Link, router } from '@inertiajs/react';
import { Plus, Star, Users } from 'lucide-react';
import { useCallback } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { create, index, show } from '@/routes/guests';

type Row = App.Data.Guests.GuestRowData;
type Filters = { search: string; status: string; tag: string };

type Props = {
    guests: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    sort: string;
    statuses: EnumOption[];
    tags: App.Data.Guests.TagData[];
    canManage: boolean;
};

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('fullName', {
        id: 'full_name',
        header: 'Name',
        cell: (info) => (
            <span className="flex items-center gap-2">
                <span className="font-medium">{info.getValue()}</span>
                {info.row.original.isVip && (
                    <Star
                        aria-label="VIP"
                        className="size-3.5 fill-current text-warning"
                    />
                )}
                {info.row.original.isAnonymized && (
                    <StatusBadge
                        status={{
                            value: 'anonymized',
                            label: 'Anonymized',
                            tone: 'neutral',
                        }}
                    />
                )}
                {info.row.original.isBlacklisted && (
                    <StatusBadge
                        status={{
                            value: 'blacklisted',
                            label: 'Blacklisted',
                            tone: 'danger',
                        }}
                    />
                )}
            </span>
        ),
    }),
    column.accessor('email', {
        header: 'Email',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('phone', {
        header: 'Phone',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('nationalityCode', {
        header: 'Nationality',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('tags', {
        header: 'Tags',
        enableSorting: false,
        cell: (info) => (
            <span className="flex flex-wrap gap-1">
                {info.getValue().map((tag) => (
                    <Badge key={tag} variant="outline">
                        {tag}
                    </Badge>
                ))}
            </span>
        ),
    }),
    column.accessor('createdAt', {
        id: 'created_at',
        header: 'Added',
        cell: (info) => <DateText date={info.getValue()} />,
    }),
];

export default function GuestsIndex({
    guests,
    filters,
    sort,
    statuses,
    tags,
    canManage,
}: Props) {
    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                (['search', 'status', 'tag'] as const)
                    .filter((key) => next[key] !== '')
                    .map((key) => [key, next[key]]),
            );

            router.get(
                index.url(undefined, {
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(next.sort && next.sort !== 'full_name'
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

    const hasFilters = Boolean(filters.search || filters.status || filters.tag);
    const tagOptions = tags.map((tag) => ({
        value: String(tag.id),
        label: tag.name,
    }));

    const addGuest = canManage ? (
        <Link href={create.url()} className={buttonVariants()}>
            <Plus data-icon="inline-start" />
            Add guest
        </Link>
    ) : undefined;

    return (
        <>
            <Head title="Guests" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader title="Guests" actions={addGuest} />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search name, email, phone"
                >
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All guests"
                        aria-label="Guest status"
                        className="w-44"
                    />
                    {tagOptions.length > 0 && (
                        <EnumSelect
                            options={tagOptions}
                            value={filters.tag}
                            onValueChange={(tag) => visit({ tag })}
                            allLabel="All tags"
                            aria-label="Tag"
                            className="w-44"
                        />
                    )}
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={guests.data}
                    getRowId={(row) => String(row.id)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    onRowClick={(row) =>
                        router.visit(show.url({ guest: row.id }))
                    }
                    pagination={{
                        currentPage: guests.meta.current_page,
                        lastPage: guests.meta.last_page,
                        from: guests.meta.from,
                        to: guests.meta.to,
                        total: guests.meta.total,
                        prevUrl: guests.meta.prev_page_url,
                        nextUrl: guests.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={Users}
                                title="No guests match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                search: '',
                                                status: '',
                                                tag: '',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Users}
                                title="No guests yet"
                                description="Guests are added here or while taking a booking."
                                action={addGuest}
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

GuestsIndex.layout = {
    breadcrumbs: [{ title: 'Guests', href: index() }],
};
