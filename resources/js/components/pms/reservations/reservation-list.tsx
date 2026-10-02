import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import type { DataTablePagination } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { ReservationMarkers, reservationSubtitle } from './reservation-parts';
import { RoomBox } from './room-box';

type Item = App.Data.Reservations.ReservationListItemData;

const column = dataTableColumns<Item>();

/**
 * The List view: one row per reservation, sorted by column header on the server.
 */
export function ReservationList({
    reservations,
    currency,
    sort,
    onSortChange,
    pagination,
    empty,
}: {
    reservations: Item[];
    currency: App.Enums.Currency | null;
    sort: string;
    onSortChange: (sort: string) => void;
    pagination: DataTablePagination;
    empty: ReactNode;
}) {
    const columns = useMemo(
        () => [
            column.accessor('reference', {
                header: 'Ref',
                cell: (info) => (
                    <span className="font-medium tabular-nums">
                        {info.getValue()}
                    </span>
                ),
            }),
            column.display({
                id: 'booker',
                header: 'Booker',
                cell: (info) => (
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <span className="flex items-center gap-1.5">
                            <span className="font-medium">
                                {info.row.original.title}
                            </span>
                            <ReservationMarkers
                                reservation={info.row.original}
                            />
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {reservationSubtitle(info.row.original)}
                        </span>
                    </span>
                ),
            }),
            column.accessor('firstArrival', {
                id: 'arrival',
                header: 'Stay',
                cell: (info) => (
                    <span className="flex flex-col">
                        <span className="flex items-center gap-1">
                            <DateText date={info.getValue()} /> →{' '}
                            <DateText date={info.row.original.lastDeparture} />
                        </span>
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {info.row.original.nights}{' '}
                            {info.row.original.nights === 1
                                ? 'night'
                                : 'nights'}
                        </span>
                    </span>
                ),
            }),
            column.accessor('unitCount', {
                header: 'Units',
                enableSorting: false,
                cell: (info) => (
                    <span className="tabular-nums">{info.getValue()}</span>
                ),
            }),
            column.display({
                id: 'rooms',
                header: 'Rooms',
                cell: (info) => (
                    <RoomBox box={info.row.original.roomBox} inline />
                ),
            }),
            column.display({
                id: 'source',
                header: 'Source',
                cell: (info) => (
                    <span className="flex flex-col">
                        <span>{info.row.original.sourceLabel}</span>
                        {info.row.original.channelName && (
                            <span className="text-xs text-muted-foreground">
                                {[
                                    info.row.original.channelName,
                                    info.row.original.externalReference,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        )}
                    </span>
                ),
            }),
            column.accessor('total', {
                header: 'Total',
                cell: (info) => (
                    <MoneyText amount={info.getValue()} currency={currency} />
                ),
            }),
            column.display({
                id: 'status',
                header: 'Status',
                cell: (info) => (
                    <StatusBadge status={info.row.original.status} />
                ),
            }),
        ],
        [currency],
    );

    return (
        <DataTable
            columns={columns}
            data={reservations}
            getRowId={(row) => String(row.id)}
            sort={sort}
            onSortChange={onSortChange}
            pagination={pagination}
            empty={empty}
        />
    );
}
