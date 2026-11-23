import { Head, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { index, show } from '@/routes/finance/pos-postings';

type Row = App.Data.Pos.PosPostingRowData;

type Props = {
    postings: {
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
    status: string | null;
    statuses: { value: string; label: string }[];
    pendingCount: number;
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

export default function PosPostingsIndex({
    postings,
    status,
    statuses,
    pendingCount,
    currency,
}: Props) {
    const columns = useMemo(
        () => [
            column.accessor('receiptNumber', {
                header: 'Receipt',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium group-hover/row:underline">
                        #{info.getValue()}
                    </span>
                ),
            }),
            column.accessor('outletName', {
                header: 'Outlet',
                enableSorting: false,
            }),
            column.accessor('type', { header: 'Type', enableSorting: false }),
            column.accessor('reference', {
                header: 'Reservation',
                enableSorting: false,
                cell: (info) => info.getValue() ?? '—',
            }),
            column.accessor('amount', {
                header: 'Amount',
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
            column.accessor('failureReason', {
                header: 'Why',
                enableSorting: false,
                cell: (info) => (
                    <span className="whitespace-normal text-muted-foreground">
                        {info.getValue() ?? '—'}
                    </span>
                ),
            }),
        ],
        [currency],
    );

    function visit(next: string) {
        router.get(
            index.url(undefined, {
                query: next === '' ? {} : { status: next },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="POS postings" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS postings"
                    description="Room charges sent by your POS. Receipts that could not be posted wait here for front desk."
                />

                {pendingCount > 0 && status !== 'pending_review' && (
                    <Alert>
                        <AlertTitle>
                            {pendingCount === 1
                                ? '1 receipt needs review.'
                                : `${pendingCount} receipts need review.`}
                        </AlertTitle>
                    </Alert>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <EnumSelect
                        options={statuses}
                        value={status ?? ''}
                        onValueChange={visit}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-48"
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={postings.data}
                    getRowId={(row) => String(row.id)}
                    sort=""
                    onSortChange={() => undefined}
                    onRowClick={(row) =>
                        router.visit(show.url({ posting: row.id }))
                    }
                    pagination={{
                        currentPage: postings.meta.current_page,
                        lastPage: postings.meta.last_page,
                        from: postings.meta.from,
                        to: postings.meta.to,
                        total: postings.meta.total,
                        prevUrl: postings.meta.prev_page_url,
                        nextUrl: postings.meta.next_page_url,
                    }}
                    empty={
                        <EmptyState
                            icon={Receipt}
                            title="No POS postings"
                            description="Receipts appear here when your POS sends a room charge."
                        />
                    }
                />
            </div>
        </>
    );
}

PosPostingsIndex.layout = {
    breadcrumbs: [{ title: 'POS postings', href: index() }],
};
