import { ReceiptText } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { toPaginationData } from '@/components/pms/pagination-footer';
import { buttonVariants } from '@/components/ui/button';
import { receipt } from '@/routes/portal/statements/payments';

type Row = App.Data.Portal.PortalPaymentRowData;

const column = dataTableColumns<Row>();

/**
 * The company's payments at the property that are not void, 25 per page. Each row links to its receipt.
 */
export function PortalPaymentsTable({
    propertyCode,
    payments,
    currency,
}: {
    propertyCode: string;
    payments: Illuminate.LengthAwarePaginator<number, Row>;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const columns = useMemo(
        () => [
            column.accessor('receiptNumber', {
                header: 'Receipt',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium">{info.getValue()}</span>
                ),
            }),
            column.accessor('receivedOn', {
                header: 'Date',
                enableSorting: false,
                cell: (info) => <DateText date={info.getValue()} />,
            }),
            column.accessor('methodName', {
                header: 'Method',
                enableSorting: false,
            }),
            column.accessor('reference', {
                header: 'Reference',
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
            column.accessor('id', {
                id: 'receipt',
                header: 'Receipt PDF',
                enableSorting: false,
                cell: (info) =>
                    info.row.original.hasReceipt ? (
                        <a
                            href={receipt.url({
                                property: propertyCode,
                                payment: info.getValue(),
                            })}
                            target="_blank"
                            rel="noreferrer"
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            Receipt
                        </a>
                    ) : (
                        <span className="text-muted-foreground">
                            Old system
                        </span>
                    ),
            }),
        ],
        [currency, propertyCode],
    );

    return (
        <DataTable
            columns={columns}
            data={payments.data}
            getRowId={(row) => String(row.id)}
            sort=""
            onSortChange={() => undefined}
            pagination={toPaginationData(payments.meta)}
            empty={
                <EmptyState
                    icon={ReceiptText}
                    title="No payments yet"
                    description="Payments the property records against your account appear here."
                />
            }
        />
    );
}
