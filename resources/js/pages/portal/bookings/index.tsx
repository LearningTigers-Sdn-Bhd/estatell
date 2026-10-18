import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { toPaginationData } from '@/components/pms/pagination-footer';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { book } from '@/routes/portal';
import { index, show } from '@/routes/portal/bookings';

type Row = App.Data.Portal.PortalBookingRowData;

const column = dataTableColumns<Row>();

export default function PortalBookings({
    bookings,
    propertyCode,
    search,
    companySuspended,
    currency,
}: {
    bookings: Illuminate.LengthAwarePaginator<number, Row>;
    propertyCode: string;
    search: string;
    companySuspended: boolean;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [term, setTerm] = useState(search);

    const columns = useMemo(
        () => [
            column.accessor('reference', {
                header: 'Our ref',
                enableSorting: false,
                cell: (info) => (
                    <Link
                        href={show({
                            property: propertyCode,
                            reservation: info.row.original.id,
                        })}
                        className="font-medium tabular-nums underline-offset-4 hover:underline"
                    >
                        {info.getValue()}
                    </Link>
                ),
            }),
            column.accessor('externalReference', {
                header: 'Your ref',
                enableSorting: false,
                cell: (info) => info.getValue() ?? '—',
            }),
            column.accessor('title', {
                header: 'Booking',
                enableSorting: false,
            }),
            column.accessor('firstArrival', {
                header: 'Stay',
                enableSorting: false,
                cell: (info) => (
                    <span className="flex items-center gap-1">
                        <DateText date={info.getValue()} /> →{' '}
                        <DateText date={info.row.original.lastDeparture} />
                    </span>
                ),
            }),
            column.accessor('rooms', {
                header: 'Rooms',
                enableSorting: false,
            }),
            column.accessor('total', {
                header: 'Total',
                enableSorting: false,
                cell: (info) => (
                    <MoneyText amount={info.getValue()} currency={currency} />
                ),
            }),
            column.accessor('status', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => <StatusBadge status={info.getValue()} />,
            }),
        ],
        [currency, propertyCode],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            index.url(
                { property: propertyCode },
                { query: term.trim() === '' ? {} : { search: term.trim() } },
            ),
            {},
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Bookings" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Bookings"
                    description="Your company's stays at this property."
                    actions={
                        companySuspended ? undefined : (
                            <Link
                                href={book({ property: propertyCode })}
                                className={buttonVariants()}
                            >
                                Book a stay
                            </Link>
                        )
                    }
                />

                <form onSubmit={submit} className="flex max-w-md gap-2">
                    <InputGroup>
                        <InputGroupAddon>
                            <Search aria-hidden />
                        </InputGroupAddon>
                        <InputGroupInput
                            type="search"
                            name="search"
                            aria-label="Search by our reference or yours"
                            placeholder="e.g. SBR-1042 or BT-2026-118"
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                        />
                    </InputGroup>
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </form>

                <DataTable
                    columns={columns}
                    data={bookings.data}
                    getRowId={(row) => String(row.id)}
                    sort=""
                    onSortChange={() => undefined}
                    pagination={toPaginationData(bookings.meta)}
                    empty={
                        <EmptyState
                            icon={CalendarDays}
                            title={
                                search === ''
                                    ? 'No bookings yet'
                                    : 'No bookings match this search'
                            }
                            description={
                                search === ''
                                    ? 'Stays you book appear here.'
                                    : 'Check the reference and try again.'
                            }
                        />
                    }
                />
            </div>
        </>
    );
}
