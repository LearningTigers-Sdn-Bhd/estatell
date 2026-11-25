import { Head, router } from '@inertiajs/react';
import { Store } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { index, show } from '@/routes/finance/pos-shifts';

type Row = App.Data.Pos.PosShiftData;

type Props = {
    shifts: {
        data: Row[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
            prev_page_url: string | null;
            next_page_url: string | null;
        };
    };
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

export default function PosShiftsIndex({ shifts, currency }: Props) {
    const currencyCode = currency?.code ?? 'MYR';

    const columns = useMemo(
        () => [
            column.accessor('shiftNumber', {
                header: 'Shift',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium group-hover/row:underline">
                        {info.getValue()}
                    </span>
                ),
            }),
            column.accessor('outletName', {
                header: 'Outlet',
                enableSorting: false,
            }),
            column.accessor('registerName', {
                header: 'Register',
                enableSorting: false,
            }),
            column.accessor('openedBy', {
                header: 'Opened by',
                enableSorting: false,
            }),
            column.accessor('statusLabel', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => (
                    <Badge
                        variant={
                            info.row.original.status === 'open'
                                ? 'default'
                                : 'secondary'
                        }
                    >
                        {info.getValue()}
                    </Badge>
                ),
            }),
            column.accessor('cashVariance', {
                header: `Cash variance (${currencyCode})`,
                enableSorting: false,
                cell: (info) =>
                    info.getValue() === null ? (
                        <span className="text-muted-foreground">Open</span>
                    ) : (
                        <span
                            className={
                                Number(info.getValue()) !== 0
                                    ? 'font-medium text-destructive'
                                    : undefined
                            }
                        >
                            <MoneyText
                                amount={info.getValue() ?? '0'}
                                currency={currency}
                                plain
                            />
                            {Number(info.getValue()) !== 0 &&
                                (Number(info.getValue()) < 0
                                    ? ' short'
                                    : ' over')}
                        </span>
                    ),
            }),
        ],
        [currency, currencyCode],
    );

    return (
        <>
            <Head title="POS shifts" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS shifts"
                    description="Register shifts with the cash variance counted at close."
                />
                <DataTable
                    columns={columns}
                    data={shifts.data}
                    getRowId={(row) => String(row.id)}
                    sort=""
                    onSortChange={() => undefined}
                    onRowClick={(row) =>
                        router.visit(show.url({ shift: row.id }))
                    }
                    pagination={{
                        currentPage: shifts.meta.current_page,
                        lastPage: shifts.meta.last_page,
                        from: shifts.meta.from,
                        to: shifts.meta.to,
                        total: shifts.meta.total,
                        prevUrl: shifts.meta.prev_page_url,
                        nextUrl: shifts.meta.next_page_url,
                    }}
                    empty={
                        <EmptyState
                            icon={Store}
                            title="No shifts yet"
                            description="A shift appears when a register opens."
                        />
                    }
                />
            </div>
        </>
    );
}

PosShiftsIndex.layout = {
    breadcrumbs: [{ title: 'POS shifts', href: index() }],
};
