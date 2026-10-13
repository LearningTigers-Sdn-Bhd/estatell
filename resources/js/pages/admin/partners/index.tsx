import { Head } from '@inertiajs/react';
import { Handshake, Plus, Users } from 'lucide-react';
import { useState } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { AddPartnerDialog } from '@/components/pms/partners/add-partner-dialog';
import { PartnerSignInMessageDialog } from '@/components/pms/partners/partner-sign-in-message-dialog';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/partners';

type Row = App.Data.Platform.AdminPartnerRowData;

type Props = {
    tab: 'active';
    partners: Illuminate.LengthAwarePaginator<number, Row>;
};

/**
 * Days left on a temp password, counted in the browser for display only. Null when expired.
 */
function temporaryPasswordDaysLeft(expiresAt: string): number | null {
    const msLeft = new Date(expiresAt).getTime() - Date.now();

    return msLeft > 0 ? Math.ceil(msLeft / 86_400_000) : null;
}

function PartnerStatus({ row }: { row: Row }) {
    if (row.temporaryPasswordExpiresAt === null) {
        return <StatusBadge status={row.status} />;
    }

    const daysLeft = temporaryPasswordDaysLeft(row.temporaryPasswordExpiresAt);

    return (
        <StatusBadge
            status={
                daysLeft === null
                    ? {
                          value: 'temp-expired',
                          label: 'Temp password expired',
                          tone: 'danger',
                      }
                    : {
                          value: 'temp',
                          label: `Temp password · ${daysLeft}d`,
                          tone: 'warning',
                      }
            }
        />
    );
}

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('name', {
        header: 'Partner',
        enableSorting: false,
        cell: (info) => <span className="font-medium">{info.getValue()}</span>,
    }),
    column.accessor('codePrefix', {
        header: 'Prefix',
        enableSorting: false,
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('email', {
        header: 'Email',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('propertiesCount', {
        header: 'Properties',
        enableSorting: false,
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.display({
        id: 'status',
        header: 'Status',
        cell: (info) => <PartnerStatus row={info.row.original} />,
    }),
    column.accessor('createdAt', {
        header: 'Created',
        enableSorting: false,
        cell: (info) => <DateText date={info.getValue()} />,
    }),
];

export default function PartnersIndex({ tab, partners }: Props) {
    const [addOpen, setAddOpen] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    return (
        <>
            <Head title="Partners" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Partners"
                    description="Resellers and consultants who bring properties to estatell."
                    actions={
                        <Button onClick={() => setAddOpen(true)}>
                            <Plus data-icon="inline-start" />
                            Add partner
                        </Button>
                    }
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="gap-4"
                    tabs={[
                        {
                            value: 'active',
                            label: 'Active',
                            icon: Users,
                            href: index(),
                        },
                    ]}
                >
                    <DataTable
                        columns={columns}
                        data={partners.data}
                        getRowId={(row) => String(row.id)}
                        sort=""
                        onSortChange={() => {}}
                        pagination={{
                            currentPage: partners.meta.current_page,
                            lastPage: partners.meta.last_page,
                            from: partners.meta.from,
                            to: partners.meta.to,
                            total: partners.meta.total,
                            prevUrl: partners.meta.prev_page_url,
                            nextUrl: partners.meta.next_page_url,
                        }}
                        empty={
                            <EmptyState
                                icon={Handshake}
                                title="No partners yet"
                            />
                        }
                    />
                </TabLinks>
            </div>

            <AddPartnerDialog
                open={addOpen}
                onOpenChange={setAddOpen}
                onAdded={setMessage}
            />
            <PartnerSignInMessageDialog
                message={message}
                onClose={() => setMessage(null)}
            />
        </>
    );
}

PartnersIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Partners', href: index() },
    ],
};
