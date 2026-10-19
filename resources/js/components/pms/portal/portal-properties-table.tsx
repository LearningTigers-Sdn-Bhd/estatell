import { Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { buttonVariants } from '@/components/ui/button';
import { show } from '@/routes/portal';

type Property = App.Data.Portal.PortalPropertyData;

const column = dataTableColumns<Property>();

/**
 * The company user's linked properties: open balance, arrivals and an Open link. Used by the Properties page and the
 * dashboard.
 */
export function PortalPropertiesTable({
    properties,
}: {
    properties: Property[];
}) {
    const columns = useMemo(
        () => [
            column.accessor('propertyName', {
                header: 'Property',
                enableSorting: false,
                cell: (info) => (
                    <span
                        className={
                            info.row.original.state === 'open'
                                ? 'font-medium'
                                : 'font-medium text-muted-foreground'
                        }
                    >
                        {info.getValue()}
                    </span>
                ),
            }),
            column.accessor('companyName', {
                header: 'Company',
                enableSorting: false,
            }),
            column.accessor('openBalance', {
                header: 'Open balance',
                enableSorting: false,
                cell: (info) =>
                    info.row.original.state === 'open' ? (
                        <MoneyText
                            amount={info.getValue()}
                            currency={info.row.original.currency}
                        />
                    ) : (
                        '—'
                    ),
            }),
            column.accessor('arrivals', {
                header: 'Arrivals, next 7 days',
                enableSorting: false,
                cell: (info) =>
                    info.row.original.state === 'open' ? info.getValue() : '—',
            }),
            column.display({
                id: 'open',
                header: '',
                cell: (info) =>
                    info.row.original.state === 'open' ? (
                        <Link
                            href={show({
                                property: info.row.original.propertyCode,
                            })}
                            className={buttonVariants({ size: 'sm' })}
                        >
                            Open
                        </Link>
                    ) : (
                        <span className="text-sm text-muted-foreground">
                            {info.row.original.stateLabel}
                        </span>
                    ),
            }),
        ],
        [],
    );

    return (
        <DataTable
            columns={columns}
            data={properties}
            getRowId={(row) => String(row.linkId)}
            sort=""
            onSortChange={() => undefined}
            pagination={{
                currentPage: 1,
                lastPage: 1,
                from: properties.length > 0 ? 1 : null,
                to: properties.length > 0 ? properties.length : null,
                total: properties.length,
                prevUrl: null,
                nextUrl: null,
            }}
            columnAlign={{
                openBalance: 'right',
                arrivals: 'right',
                open: 'right',
            }}
            empty={
                <EmptyState
                    icon={Building2}
                    title="No properties yet"
                    description="A property links your login to your company. Ask the property to set your email as the company's portal login."
                />
            }
        />
    );
}
