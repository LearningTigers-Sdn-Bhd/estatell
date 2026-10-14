import { Head, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { SignupLinkCard } from '@/components/pms/partners/signup-link-card';
import { PageHeader } from '@/components/pms/page-header';
import { CreatePropertySheet } from '@/components/pms/properties/create-property-sheet';
import type { SetupMethod } from '@/components/pms/properties/create-property-sheet';
import { StatusBadge } from '@/components/pms/status-badge';
import { index, show, store } from '@/routes/partner/properties';

type Row = App.Data.Platform.PartnerPropertyRowData;

type Props = {
    partnerName: string;
    signupUrl: string;
    canCreateProperties: boolean;
    properties: Illuminate.LengthAwarePaginator<number, Row>;
    types?: EnumOption[];
    setupMethods?: SetupMethod[];
    passwordRules?: string;
};

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('code', {
        header: 'Code',
        enableSorting: false,
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('name', {
        header: 'Name',
        enableSorting: false,
        cell: (info) => <span className="font-medium">{info.getValue()}</span>,
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    column.accessor('createdAt', {
        header: 'Created',
        enableSorting: false,
        cell: (info) => <DateText date={info.getValue()} />,
    }),
];

export default function PartnerPropertiesIndex({
    partnerName,
    signupUrl,
    canCreateProperties,
    properties,
    types = [],
    setupMethods = [],
    passwordRules = '',
}: Props) {
    return (
        <>
            <Head title="Properties" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <SignupLinkCard
                    partnerName={partnerName}
                    signupUrl={signupUrl}
                />

                <PageHeader
                    title="Properties"
                    actions={
                        canCreateProperties && (
                            <CreatePropertySheet
                                form={store.form()}
                                label="New property"
                                types={types}
                                setupMethods={setupMethods}
                                passwordRules={passwordRules}
                            />
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    data={properties.data}
                    getRowId={(row) => row.code}
                    sort=""
                    onSortChange={() => {}}
                    onRowClick={(row) => router.visit(show(row.code))}
                    pagination={{
                        currentPage: properties.meta.current_page,
                        lastPage: properties.meta.last_page,
                        from: properties.meta.from,
                        to: properties.meta.to,
                        total: properties.meta.total,
                        prevUrl: properties.meta.prev_page_url,
                        nextUrl: properties.meta.next_page_url,
                    }}
                    empty={
                        <EmptyState
                            icon={Building2}
                            title="Share your signup link to add a property."
                        />
                    }
                />
            </div>
        </>
    );
}

PartnerPropertiesIndex.layout = {
    breadcrumbs: [{ title: 'Properties', href: index() }],
};
