import { Head, router } from '@inertiajs/react';
import { CalendarCheck, Search } from 'lucide-react';
import { Fragment, useState } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { formatDate } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { formatMoney } from '@/components/pms/money-text';
import { GroupExportMenu } from '@/components/pms/group/group-export-menu';
import {
    PropertySectionRow,
    startsProperty,
} from '@/components/pms/group/property-section-row';
import {
    ReservationPeekSheet,
    type PeekTarget,
} from '@/components/pms/group/reservation-peek-sheet';
import {
    PaginationFooter,
    toPaginationData,
} from '@/components/pms/pagination-footer';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { dashboard as groupDashboard, reservations } from '@/routes/group';
import {
    exportMethod as exportCsv,
    pdf as exportPdf,
} from '@/routes/group/reservations';

type Group = { id: number; name: string };
type Row = App.Data.Group.GroupReservationRowData;
type Filters = {
    search: string | null;
    status: string | null;
    from: string | null;
    to: string | null;
    property: string | null;
};
type Page = {
    data: Row[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/** A money amount in the row's own currency. */
function amount(value: string, currency: string): string {
    return formatMoney(value, { code: currency, name: currency, decimals: 2 });
}

/**
 * Group › Reservations: every property's bookings in one list. Find a guest or a reference anywhere, filter by status,
 * stay dates and property, open a booking in its property, or export the list.
 * See docs/03-staff/05-group-mode.md#reservations.
 */
export default function GroupReservations({
    group,
    filters,
    statuses,
    properties,
    leftOut,
    reservations: page,
}: {
    group: Group;
    filters: Filters;
    statuses: EnumOption[];
    properties: App.Data.Property.PropertySummaryData[];
    leftOut: string[];
    reservations: Page;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [peek, setPeek] = useState<PeekTarget>(null);
    const query = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };

        return Object.fromEntries(
            Object.entries(merged).filter(
                ([, value]) => value !== null && value !== '',
            ),
        ) as Record<string, string>;
    };
    const visit = (next: Partial<Filters>) =>
        router.get(reservations.url({ propertyGroup: group.id }), query(next), {
            preserveState: true,
            replace: true,
        });

    return (
        <>
            <Head title={`${group.name} reservations`} />

            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        className="flex items-center gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            visit({
                                search: search,
                                from: search === '' ? filters.from : null,
                                to: search === '' ? filters.to : null,
                            });
                        }}
                    >
                        <Input
                            type="search"
                            aria-label="Search reservations"
                            placeholder="Guest, email, phone or reference"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="w-72"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            size="icon"
                            aria-label="Search"
                        >
                            <Search />
                        </Button>
                    </form>
                    <DateRangePicker
                        from={filters.from ?? ''}
                        to={filters.to ?? ''}
                        onChange={(from, to) => visit({ from, to })}
                        placeholder="Any dates"
                        weekStartsOn={1}
                        className="w-auto"
                    />
                    <EnumSelect
                        aria-label="Status"
                        className="w-40"
                        allLabel="All statuses"
                        options={statuses}
                        value={filters.status ?? ''}
                        onValueChange={(status) => visit({ status })}
                    />
                    {properties.length > 1 && (
                        <EnumSelect
                            aria-label="Property"
                            className="w-52"
                            allLabel="All properties"
                            options={properties.map((property) => ({
                                value: property.code,
                                label: property.name,
                            }))}
                            value={filters.property ?? ''}
                            onValueChange={(property) => visit({ property })}
                        />
                    )}
                    <div className="ml-auto">
                        <GroupExportMenu
                            pdfUrl={exportPdf.url(
                                { propertyGroup: group.id },
                                { query: query({}) },
                            )}
                            csvUrl={exportCsv.url(
                                { propertyGroup: group.id },
                                { query: query({}) },
                            )}
                        />
                    </div>
                </div>

                {leftOut.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Not included: {leftOut.join(', ')}. Your role there does
                        not show reservations.
                    </p>
                )}

                {page.data.length === 0 ? (
                    <EmptyState
                        icon={CalendarCheck}
                        title="No bookings found"
                        description="Try another search, status or dates."
                    />
                ) : (
                    <div className="flex flex-col gap-3">
                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Booking</TableHead>
                                        <TableHead>Stay</TableHead>
                                        <TableHead className="text-right">
                                            Rooms
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Pax
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Source</TableHead>
                                        <TableHead className="text-right">
                                            Room total
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Balance
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {page.data.map((row, index) => (
                                        <Fragment
                                            key={`${row.propertyCode}-${row.id}`}
                                        >
                                            {startsProperty(
                                                page.data,
                                                index,
                                                (item) => item.propertyCode,
                                            ) && (
                                                <PropertySectionRow
                                                    name={row.propertyName}
                                                    code={row.propertyCode}
                                                    colSpan={8}
                                                />
                                            )}
                                            <TableRow
                                                className="cursor-pointer"
                                                data-state={
                                                    peek?.reservationId ===
                                                        row.id &&
                                                    peek.propertyCode ===
                                                        row.propertyCode
                                                        ? 'selected'
                                                        : undefined
                                                }
                                                onClick={() =>
                                                    setPeek({
                                                        propertyCode:
                                                            row.propertyCode,
                                                        reservationId: row.id,
                                                    })
                                                }
                                            >
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        <button
                                                            type="button"
                                                            className="text-left font-medium underline-offset-2 hover:underline focus-visible:underline focus-visible:outline-none"
                                                            aria-label={`Show ${row.guest ?? row.reference} at ${row.propertyName}`}
                                                            onClick={(
                                                                event,
                                                            ) => {
                                                                event.stopPropagation();
                                                                setPeek({
                                                                    propertyCode:
                                                                        row.propertyCode,
                                                                    reservationId:
                                                                        row.id,
                                                                });
                                                            }}
                                                        >
                                                            {row.guest ??
                                                                row.reference}
                                                        </button>
                                                        {row.guest !== null && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {row.reference}
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap tabular-nums">
                                                    {row.arrival &&
                                                    row.departure
                                                        ? `${formatDate(row.arrival)} → ${formatDate(row.departure)}`
                                                        : '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {row.rooms}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {row.pax}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={row.status}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {row.source ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {amount(
                                                        row.total,
                                                        row.currency,
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        'text-right tabular-nums',
                                                        Number(row.balance) >
                                                            0 &&
                                                            'text-destructive',
                                                    )}
                                                >
                                                    {amount(
                                                        row.balance,
                                                        row.currency,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        </Fragment>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <PaginationFooter pagination={toPaginationData(page)} />
                    </div>
                )}
            </div>
            <ReservationPeekSheet
                groupId={group.id}
                target={peek}
                onClose={() => setPeek(null)}
            />
        </>
    );
}

GroupReservations.layout = (props: { group: Group }) => ({
    breadcrumbs: [
        {
            title: props.group.name,
            href: groupDashboard({ propertyGroup: props.group.id }),
        },
        {
            title: 'Reservations',
            href: reservations({ propertyGroup: props.group.id }),
        },
    ],
});
