import { Head } from '@inertiajs/react';
import { Handshake, Pause, Plus, Users } from 'lucide-react';
import { useState } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { AddPartnerDialog } from '@/components/pms/partners/add-partner-dialog';
import {
    ActivePartnerActions,
    SuspendedPartnerActions,
} from '@/components/pms/partners/partner-row-actions';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import type { EnumOption } from '@/components/pms/enum-select';
import { ExportTemporaryPasswordsMenu } from '@/components/pms/temporary-passwords/export-temporary-passwords-menu';
import { ResetTemporaryPassword } from '@/components/pms/temporary-passwords/reset-temporary-password';
import { TemporaryPasswordMessageDialog } from '@/components/pms/temporary-passwords/temporary-password-message-dialog';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import { index, suspended } from '@/routes/admin/partners';
import {
    exportMethod,
    message,
    reset,
} from '@/routes/admin/partners/temporary-passwords';

type Row = App.Data.Platform.AdminPartnerRowData;

type Props = {
    tab: 'active' | 'suspended';
    exportFormats?: EnumOption[];
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
];

const createdColumn = column.accessor('createdAt', {
    header: 'Created',
    enableSorting: false,
    cell: (info) => <DateText date={info.getValue()} />,
});

const activeColumns = (onShowMessage: (userIds: number[]) => void) => [
    ...columns,
    column.display({
        id: 'status',
        header: 'Status',
        cell: (info) => <PartnerStatus row={info.row.original} />,
    }),
    createdColumn,
    column.display({
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        cell: (info) => (
            <ActivePartnerActions
                partner={info.row.original}
                onShowMessage={onShowMessage}
            />
        ),
    }),
];

const suspendedColumns = [
    ...columns,
    column.accessor('suspendedAt', {
        header: 'Suspended',
        enableSorting: false,
        cell: (info) => {
            const date = info.getValue();

            return date === null ? '—' : <DateText date={date} />;
        },
    }),
    createdColumn,
    column.display({
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        cell: (info) => <SuspendedPartnerActions partner={info.row.original} />,
    }),
];

function SelectionBar({
    selected,
    exportFormats,
    onShowMessage,
    onClear,
}: {
    selected: number[];
    exportFormats: EnumOption[];
    onShowMessage: (userIds: number[]) => void;
    onClear: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/50 px-3 py-2">
            <span className="text-sm font-medium">
                Selected ({selected.length})
            </span>
            <Button
                variant="outline"
                size="sm"
                onClick={() => onShowMessage(selected)}
            >
                Copy message
            </Button>
            <ExportTemporaryPasswordsMenu
                route={exportMethod()}
                userIds={selected}
                formats={exportFormats}
                size="sm"
            />
            <ResetTemporaryPassword
                route={reset()}
                skippedNote="Suspended partners are skipped."
                userIds={selected}
                target={`${selected.length} partners`}
                variant="outline"
                onReset={(userIds) => {
                    onShowMessage(userIds);
                    onClear();
                }}
            />
            <Button
                variant="ghost"
                size="sm"
                className="ms-auto"
                onClick={onClear}
            >
                Clear
            </Button>
        </div>
    );
}

export default function PartnersIndex({
    tab,
    partners,
    exportFormats = [],
}: Props) {
    const [addOpen, setAddOpen] = useState(false);
    const [messageUserIds, setMessageUserIds] = useState<number[] | null>(null);
    const [chosen, setChosen] = useState<Set<string>>(new Set());
    // Partners that left the list (suspended, for example) drop out of the selection.
    const selected = partners.data
        .filter((row) => tab === 'active' && chosen.has(String(row.userId)))
        .map((row) => row.userId);

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
                        {
                            value: 'suspended',
                            label: 'Suspended',
                            icon: Pause,
                            href: suspended(),
                        },
                    ]}
                >
                    {tab === 'active' && selected.length > 0 && (
                        <SelectionBar
                            selected={selected}
                            exportFormats={exportFormats}
                            onShowMessage={setMessageUserIds}
                            onClear={() => setChosen(new Set())}
                        />
                    )}
                    <DataTable
                        columns={
                            tab === 'active'
                                ? activeColumns(setMessageUserIds)
                                : suspendedColumns
                        }
                        data={partners.data}
                        getRowId={(row) => String(row.userId)}
                        selection={
                            tab === 'active'
                                ? { selected: chosen, onChange: setChosen }
                                : undefined
                        }
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
                                title={
                                    tab === 'active'
                                        ? 'No partners yet'
                                        : 'No suspended partners'
                                }
                            />
                        }
                    />
                </TabLinks>
            </div>

            <AddPartnerDialog
                open={addOpen}
                onOpenChange={setAddOpen}
                onAdded={(userId) => setMessageUserIds([userId])}
            />
            <TemporaryPasswordMessageDialog
                route={message()}
                who="partner"
                userIds={messageUserIds ?? []}
                open={messageUserIds !== null}
                onOpenChange={(open) => !open && setMessageUserIds(null)}
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
