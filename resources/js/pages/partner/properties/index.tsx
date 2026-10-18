import { Head, Link, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { DemoAccessCard } from '@/components/pms/partners/demo-access-card';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { SignupLinkCard } from '@/components/pms/partners/signup-link-card';
import { PageHeader } from '@/components/pms/page-header';
import { CreatePropertySheet } from '@/components/pms/properties/create-property-sheet';
import type { SetupMethod } from '@/components/pms/properties/create-property-sheet';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import { index, show, store } from '@/routes/partner/properties';
import { show as viewAs } from '@/routes/view-as';

type Row = App.Data.Platform.PartnerPropertyRowData;

type Props = {
    partnerName: string;
    signupUrl: string;
    canCreateProperties: boolean;
    properties: Illuminate.LengthAwarePaginator<number, Row>;
    demoAccess: App.Data.Platform.DemoAccessData | null;
    types?: EnumOption[];
    countries?: EnumOption[];
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
    column.display({
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        cell: (info) => (
            <span
                className="flex justify-end"
                onClick={(event) => event.stopPropagation()}
            >
                <Link
                    href={viewAs(info.row.original.code)}
                    className={buttonVariants({
                        variant: 'outline',
                        size: 'sm',
                    })}
                >
                    View as
                </Link>
            </span>
        ),
    }),
];

export default function PartnerPropertiesIndex({
    partnerName,
    signupUrl,
    canCreateProperties,
    properties,
    demoAccess,
    types = [],
    countries = [],
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

                {demoAccess && <DemoAccessCard access={demoAccess} />}

                <PageHeader
                    title="Properties"
                    actions={
                        canCreateProperties && (
                            <CreatePropertySheet
                                form={store.form()}
                                label="New property"
                                types={types}
                                countries={countries}
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
