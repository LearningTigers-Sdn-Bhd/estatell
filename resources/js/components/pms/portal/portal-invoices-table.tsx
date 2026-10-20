import { FileText } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { toPaginationData } from '@/components/pms/pagination-footer';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import { pdf as invoicePdf } from '@/routes/portal/statements/invoices';

type Row = App.Data.CityLedger.ArInvoiceRowData;

const column = dataTableColumns<Row>();

/**
 * The company's invoices and credit notes at the property, 25 per page. Each row links to its PDF. Nothing sorts, so
 * the page never asks the server for a new order.
 */
export function PortalInvoicesTable({
    propertyCode,
    invoices,
    currency,
}: {
    propertyCode: string;
    invoices: Illuminate.LengthAwarePaginator<number, Row>;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const columns = useMemo(
        () => [
            column.accessor('number', {
                header: 'Number',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium">{info.getValue()}</span>
                ),
            }),
            column.accessor('issuedOn', {
                header: 'Issued',
                enableSorting: false,
                cell: (info) => <DateText date={info.getValue()} />,
            }),
            column.accessor('dueOn', {
                header: 'Due',
                enableSorting: false,
                cell: (info) => <DateText date={info.getValue()} />,
            }),
            column.accessor('amount', {
                header: 'Amount',
                enableSorting: false,
                cell: (info) => (
                    <MoneyText amount={info.getValue()} currency={currency} />
                ),
            }),
            column.accessor('balance', {
                header: 'Balance',
                enableSorting: false,
                cell: (info) =>
                    info.getValue() === null ? (
                        '—'
                    ) : (
                        <MoneyText
                            amount={info.getValue() ?? '0.00'}
                            currency={currency}
                        />
                    ),
            }),
            column.accessor('status', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => <StatusBadge status={info.getValue()} />,
            }),
            column.accessor('overdue', {
                header: 'Overdue',
                enableSorting: false,
                cell: (info) =>
                    info.getValue() ? (
                        <span className="text-destructive">Overdue</span>
                    ) : (
                        '—'
                    ),
            }),
            column.accessor('id', {
                id: 'pdf',
                header: 'PDF',
                enableSorting: false,
                cell: (info) =>
                    info.row.original.migrated ? (
                        <span className="text-muted-foreground">
                            Old system
                        </span>
                    ) : (
                        <a
                            href={invoicePdf.url({
                                property: propertyCode,
                                invoice: info.getValue(),
                            })}
                            target="_blank"
                            rel="noreferrer"
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            PDF
                        </a>
                    ),
            }),
        ],
        [currency, propertyCode],
    );

    return (
        <DataTable
            columns={columns}
            data={invoices.data}
            getRowId={(row) => String(row.id)}
            sort=""
            onSortChange={() => undefined}
            pagination={toPaginationData(invoices.meta)}
            empty={
                <EmptyState
                    icon={FileText}
                    title="No invoices yet"
                    description="Invoices from this property appear here once they are issued."
                />
            }
        />
    );
}
