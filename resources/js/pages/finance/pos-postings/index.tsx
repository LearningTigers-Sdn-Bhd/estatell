import { Head, router } from '@inertiajs/react';
import {
    CircleCheck,
    EyeOff,
    Receipt,
    TriangleAlert,
    Undo2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatCard } from '@/components/pms/stat-card';
import { StatusBadge } from '@/components/pms/status-badge';
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
    summary: {
        status: string;
        label: string;
        count: number;
        amount: string;
    }[];
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

const statusIcons: Record<string, LucideIcon> = {
    posted: CircleCheck,
    reversed: Undo2,
    pending_review: TriangleAlert,
    ignored: EyeOff,
};

/** The status that needs action comes first. */
const cardOrder = ['pending_review', 'posted', 'reversed', 'ignored'];

export default function PosPostingsIndex({
    postings,
    status,
    statuses,
    summary,
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
            column.accessor('createdAt', {
                header: 'Received',
                enableSorting: false,
                cell: (info) => (
                    <span className="tabular-nums">
                        {new Date(info.getValue()).toLocaleString('en-GB', {
                            day: 'numeric',
                            month: 'short',
                            hour: 'numeric',
                            minute: '2-digit',
                            hour12: true,
                        })}
                    </span>
                ),
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

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="POS postings"
                    description="Room charges sent by your POS. Receipts that could not be posted wait here for front desk."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {cardOrder
                        .map((key) => summary.find((row) => row.status === key))
                        .filter((row) => row !== undefined)
                        .map((row) => (
                            <StatCard
                                key={row.status}
                                icon={statusIcons[row.status] ?? Receipt}
                                label={row.label}
                                value={row.count}
                                hint={
                                    <MoneyText
                                        amount={row.amount}
                                        currency={currency}
                                    />
                                }
                                pressed={status === row.status}
                                onClick={() =>
                                    visit(
                                        status === row.status ? '' : row.status,
                                    )
                                }
                            />
                        ))}
                </div>

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
