import { Head, router } from '@inertiajs/react';
import { Plus, Settings2, Users } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { AddGuestSheet } from '@/components/pms/guests/add-guest-sheet';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { GuestFlags } from '@/components/pms/guests/guest-flags';
import { GuestBulkActions } from '@/components/pms/guests/guest-bulk-actions';
import { GuestRowActions } from '@/components/pms/guests/guest-row-actions';
import type { GuestRowPermissions } from '@/components/pms/guests/guest-row-actions';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { ReportLayoutSheet } from '@/components/pms/reports/report-layout-sheet';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { index, show } from '@/routes/guests';

type Row = App.Data.Guests.GuestRowData;
type Filters = {
    search: string;
    status: string;
    tag: string;
    source: string;
    returning: string;
    contact: string;
};
type Currency = App.Data.Property.CurrencyData | null;
type Layout = App.Data.Reports.ReportLayoutData;

const FILTER_KEYS = [
    'search',
    'status',
    'tag',
    'source',
    'returning',
    'contact',
] as const;

type Props = {
    guests: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    sort: string;
    statuses: EnumOption[];
    tags: App.Data.Guests.TagData[];
    sources: EnumOption[];
    returningOptions: EnumOption[];
    contactOptions: EnumOption[];
    currency: Currency;
    /** The column layout of the list. Name always shows, wherever it is placed. */
    layout: Layout;
    canEditLayout: boolean;
    canBlacklist: boolean;
    canAnonymize: boolean;
    countries: EnumOption[];
    canManage: boolean;
    bulkActions: EnumOption[];
    maxBulkGuests: number;
};

const column = dataTableColumns<Row>();

/** The contact value, or a warning when it is missing, so staff ask for it at the next stay. */
function ContactCell({
    value,
    missing,
}: {
    value: string | null;
    missing: string;
}) {
    return value ? value : <span className="text-warning">{missing}</span>;
}

function guestColumns(currency: Currency) {
    return [
        column.accessor('fullName', {
            id: 'full_name',
            header: 'Name',
            cell: (info) => (
                <span className="flex items-center gap-2">
                    <span className="font-medium group-hover/row:underline">
                        {info.getValue()}
                    </span>
                    <GuestFlags
                        isVip={info.row.original.isVip}
                        isReturning={info.row.original.stays >= 2}
                        isAnonymized={info.row.original.isAnonymized}
                        isBlacklisted={info.row.original.isBlacklisted}
                    />
                </span>
            ),
        }),
        column.accessor('email', {
            id: 'email',
            header: 'Email',
            cell: (info) =>
                info.row.original.isAnonymized ? (
                    '—'
                ) : (
                    <ContactCell value={info.getValue()} missing="No email" />
                ),
        }),
        column.accessor('phone', {
            id: 'phone',
            header: 'Phone',
            cell: (info) =>
                info.row.original.isAnonymized ? (
                    '—'
                ) : (
                    <ContactCell value={info.getValue()} missing="No phone" />
                ),
        }),
        column.accessor('nationalityName', {
            id: 'nationality',
            header: 'Nationality',
            cell: (info) => info.getValue() ?? '—',
        }),
        column.accessor('tags', {
            id: 'tags',
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
        column.accessor('lastVisit', {
            id: 'last_visit',
            header: 'Last visit',
            sortDescFirst: true,
            cell: (info) => {
                const row = info.row.original;

                if (row.stays === 0) {
                    return '—';
                }

                return (
                    <span className="flex flex-col">
                        <span className="tabular-nums">
                            {row.inHouse ? (
                                'In house now'
                            ) : (
                                <DateText date={info.getValue()} />
                            )}
                        </span>
                        {row.source && (
                            <span className="truncate text-xs text-muted-foreground">
                                {row.source}
                            </span>
                        )}
                    </span>
                );
            },
        }),
        column.accessor('stays', {
            id: 'stays',
            header: 'Stays',
            sortDescFirst: true,
            cell: (info) => info.getValue(),
        }),
        column.accessor('spend', {
            id: 'spend',
            header: currency ? `Spend (${currency.code})` : 'Spend',
            sortDescFirst: true,
            cell: (info) => {
                const spend = info.getValue();

                return spend === null ? (
                    '—'
                ) : (
                    <MoneyText amount={spend} currency={currency} plain />
                );
            },
        }),
        column.accessor('createdAt', {
            id: 'created_at',
            header: 'Added',
            cell: (info) => <DateText date={info.getValue()} />,
        }),
    ];
}

/**
 * The visible layout columns in the layout order, then the ⋯ menu, which is not in the layout. Name always shows: it
 * opens the guest.
 */
function layoutColumns(
    currency: Currency,
    layout: Layout,
    permissions: GuestRowPermissions,
) {
    const byId = new Map(guestColumns(currency).map((one) => [one.id, one]));

    return [
        ...layout.columns
            .filter((one) => one.visible || one.key === 'full_name')
            .flatMap((one) => byId.get(one.key) ?? []),
        column.display({
            id: 'actions',
            header: () => <span className="sr-only">Actions</span>,
            cell: (info) => (
                <GuestRowActions row={info.row.original} {...permissions} />
            ),
        }),
    ];
}

export default function GuestsIndex({
    guests,
    filters,
    sort,
    statuses,
    tags,
    sources,
    returningOptions,
    contactOptions,
    currency,
    layout,
    canEditLayout,
    canBlacklist,
    canAnonymize,
    countries,
    canManage,
    bulkActions,
    maxBulkGuests,
}: Props) {
    const [layoutDraft, setLayoutDraft] = useState<Layout | null>(null);
    const shownLayout = layoutDraft ?? layout;
    const columns = useMemo(
        () =>
            layoutColumns(currency, shownLayout, {
                canManage,
                canBlacklist,
                canAnonymize,
            }),
        [currency, shownLayout, canManage, canBlacklist, canAnonymize],
    );
    const columnAlign = {
        ...Object.fromEntries(
            shownLayout.columns.map((one) => [one.key, one.align]),
        ),
        actions: 'right' as const,
    };
    const [adding, setAdding] = useState(false);
    const [busy, setBusy] = useState(false);
    const scope = JSON.stringify(filters);
    const [selection, setSelection] = useState({
        scope,
        ids: new Set<string>(),
    });
    if (selection.scope !== scope) {
        setSelection({ scope, ids: new Set<string>() });
    }
    const selected =
        selection.scope === scope ? selection.ids : new Set<string>();
    const canSelect = bulkActions.length > 0 && filters.status !== 'anonymized';

    function select(ids: Set<string>) {
        if (ids.size > maxBulkGuests) {
            toast.add({
                title: `Select up to ${maxBulkGuests} guests at a time.`,
                type: 'error',
            });
            return;
        }
        setSelection({ scope, ids });
    }
    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                FILTER_KEYS.filter((key) => next[key] !== '').map((key) => [
                    key,
                    next[key],
                ]),
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

    const hasFilters = FILTER_KEYS.some((key) => filters[key] !== '');
    const tagOptions = tags.map((tag) => ({
        value: String(tag.id),
        label: tag.name,
    }));

    const addGuest = canManage ? (
        <Button onClick={() => setAdding(true)}>
            <Plus data-icon="inline-start" />
            Add guest
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Guests" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Guests"
                    actions={
                        <>
                            {canEditLayout && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setLayoutDraft(layout)}
                                >
                                    <Settings2 data-icon="inline-start" />
                                    Layout
                                </Button>
                            )}
                            {addGuest}
                        </>
                    }
                />

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
                    {sources.length > 0 && (
                        <EnumSelect
                            options={sources}
                            value={filters.source}
                            onValueChange={(source) => visit({ source })}
                            allLabel="All sources"
                            aria-label="Source"
                            className="w-44"
                        />
                    )}
                    <EnumSelect
                        options={returningOptions}
                        value={filters.returning}
                        onValueChange={(returning) => visit({ returning })}
                        allLabel="All stays"
                        aria-label="Returning"
                        className="w-40"
                    />
                    <EnumSelect
                        options={contactOptions}
                        value={filters.contact}
                        onValueChange={(contact) => visit({ contact })}
                        allLabel="Any contact"
                        aria-label="Contact"
                        className="w-52"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={guests.data}
                    getRowId={(row) => String(row.id)}
                    selection={
                        canSelect
                            ? {
                                  selected,
                                  onChange: (next) => {
                                      if (!busy) {
                                          select(next);
                                      }
                                  },
                              }
                            : undefined
                    }
                    columnAlign={columnAlign}
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
                                                source: '',
                                                returning: '',
                                                contact: '',
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

                {canSelect && (
                    <GuestBulkActions
                        key={scope}
                        selected={selected}
                        actions={bulkActions}
                        onSelectionChange={select}
                        onBusyChange={setBusy}
                    />
                )}
            </div>

            {canEditLayout && layoutDraft && (
                <ReportLayoutSheet
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setLayoutDraft(null);
                        }
                    }}
                    report="guests"
                    reportLabel="Guests"
                    saved={layout}
                    draft={layoutDraft}
                    onDraftChange={setLayoutDraft}
                    orientations={[]}
                    dates={{}}
                    onDone={() => setLayoutDraft(null)}
                    printable={false}
                    lockedKeys={['full_name']}
                    description="Choose the columns, in the order they show, and how each aligns. Name always shows. The table behind this panel shows your changes."
                />
            )}

            {canManage && (
                <AddGuestSheet
                    open={adding}
                    onOpenChange={setAdding}
                    countries={countries}
                />
            )}
        </>
    );
}

GuestsIndex.layout = {
    breadcrumbs: [{ title: 'Guests', href: index() }],
};
