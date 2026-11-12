import { Head, router } from '@inertiajs/react';
import { FileDown, ReceiptText } from 'lucide-react';
import { PayoutTabs } from '@/components/pms/commissions/payout-tabs';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import { index as payouts } from '@/routes/finance/payouts';
import { pdf, show } from '@/routes/finance/payouts/vouchers';

type Row = App.Data.Commissions.PayoutVoucherRowData;

type Props = {
    vouchers: Illuminate.LengthAwarePaginator<number, Row>;
};

const column = dataTableColumns<Row>();

/** The voucher columns. The amount header carries the property currency, so its cells are plain. */
const voucherColumns = (currency: App.Data.Property.CurrencyData | null) => [
    column.accessor('number', {
        header: 'Voucher',
        enableSorting: false,
        cell: (info) => (
            <span className="font-medium tabular-nums">{info.getValue()}</span>
        ),
    }),
    column.accessor('periodEnd', {
        header: 'Cycle',
        enableSorting: false,
        cell: (info) => (
            <span className="whitespace-nowrap">
                <DateText date={info.row.original.periodStart} /> –{' '}
                <DateText date={info.getValue()} />
            </span>
        ),
    }),
    column.accessor('paidOn', {
        header: 'Paid on',
        enableSorting: false,
        cell: (info) => {
            const paidOn = info.getValue();

            return paidOn ? <DateText date={paidOn} /> : '—';
        },
    }),
    column.accessor('amount', {
        id: 'amount',
        header: currency ? `Amount (${currency.code})` : 'Amount',
        enableSorting: false,
        cell: (info) => (
            <MoneyText
                amount={info.getValue()}
                currency={info.row.original.currency}
                plain
            />
        ),
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    column.display({
        id: 'pdf',
        header: () => <span className="sr-only">PDF</span>,
        cell: (info) => (
            <a
                href={pdf.url({ payoutVoucher: info.row.original.id })}
                target="_blank"
                rel="noreferrer"
                onClick={(event) => event.stopPropagation()}
                className={buttonVariants({ variant: 'ghost', size: 'sm' })}
            >
                <FileDown aria-hidden />
                PDF
            </a>
        ),
    }),
];

/**
 * The property's payout vouchers, newest cycle first. Draft vouchers are left out.
 * See docs/30-commission-fees/06-property-payouts.md#payouts-tab.
 */
export default function PayoutVouchersIndex({ vouchers }: Props) {
    // Every voucher of the property has the property currency, so the first row names it.
    const columns = voucherColumns(vouchers.data[0]?.currency ?? null);

    return (
        <>
            <Head title="Payouts" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Payouts"
                    description="Payments from Estatell for each cycle from the 16th to the 15th, paid on the 1st."
                />

                <PayoutTabs activeTab="payouts">
                    <DataTable
                        columns={columns}
                        data={vouchers.data}
                        getRowId={(row) => String(row.id)}
                        columnAlign={{ amount: 'right', pdf: 'right' }}
                        sort=""
                        onSortChange={() => {}}
                        onRowClick={(row) =>
                            router.visit(show.url({ payoutVoucher: row.id }))
                        }
                        pagination={{
                            currentPage: vouchers.meta.current_page,
                            lastPage: vouchers.meta.last_page,
                            from: vouchers.meta.from,
                            to: vouchers.meta.to,
                            total: vouchers.meta.total,
                            prevUrl: vouchers.meta.prev_page_url,
                            nextUrl: vouchers.meta.next_page_url,
                        }}
                        empty={
                            <EmptyState
                                icon={ReceiptText}
                                title="No payouts yet"
                                description="A voucher shows here once Estatell approves it after the cycle ends on the 15th."
                            />
                        }
                    />
                </PayoutTabs>
            </div>
        </>
    );
}

PayoutVouchersIndex.layout = {
    breadcrumbs: [{ title: 'Payouts', href: payouts() }],
};
