import { Bot } from 'lucide-react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';

export type AiThreadRow = App.Data.AiAssistant.AiThreadRowData;

/** How long ago, as "12 min", "2 h 10 min" or "3 days". */
export function timeAgo(at: string): string {
    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(at).getTime()) / 60000),
    );

    if (minutes < 60) {
        return `${minutes} min`;
    }

    if (minutes < 48 * 60) {
        return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    }

    return `${Math.round(minutes / 1440)} days`;
}

/** The stay's guest and room, the name n8n sent, or "Lobby guest" ("Guest" on the chat API) when unknown. */
export function guestLabel(row: AiThreadRow): string {
    if (!row.guestName) {
        return row.place === 'api' ? 'Guest' : 'Lobby guest';
    }

    return row.roomNumber
        ? `${row.guestName} · ${row.roomNumber}`
        : row.guestName;
}

const column = dataTableColumns<AiThreadRow>();

const columns = [
    column.accessor('lastMessageAt', {
        header: 'Last message',
        enableSorting: false,
        cell: (info) => (
            <span className="tabular-nums">{timeAgo(info.getValue())}</span>
        ),
    }),
    column.accessor('placeLabel', {
        header: 'Place',
        enableSorting: false,
    }),
    column.display({
        id: 'guest',
        header: 'Guest',
        cell: (info) => (
            <span className="block max-w-56 truncate">
                {guestLabel(info.row.original)}
            </span>
        ),
    }),
    column.accessor('preview', {
        header: 'Summary',
        enableSorting: false,
        cell: (info) => (
            <span
                className={
                    info.row.original.hasSummary
                        ? 'block max-w-96 truncate'
                        : 'block max-w-96 truncate text-muted-foreground'
                }
            >
                {info.getValue() ?? '–'}
            </span>
        ),
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    column.accessor('turns', {
        header: 'Turns',
        enableSorting: false,
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
];

/**
 * A server-paginated list of AI chats. A row opens the chat Sheet.
 */
export function AiThreadsTable({
    threads,
    handedOver,
    hasFilters,
    onClearFilters,
    onOpen,
}: {
    threads: Illuminate.LengthAwarePaginator<number, AiThreadRow>;
    /** The Handed over tab, for its empty text. */
    handedOver: boolean;
    hasFilters: boolean;
    onClearFilters: () => void;
    onOpen: (row: AiThreadRow) => void;
}) {
    return (
        <DataTable
            columns={columns}
            data={threads.data}
            getRowId={(row) => row.id}
            sort=""
            onSortChange={() => undefined}
            onRowClick={onOpen}
            pagination={{
                currentPage: threads.meta.current_page,
                lastPage: threads.meta.last_page,
                from: threads.meta.from,
                to: threads.meta.to,
                total: threads.meta.total,
                prevUrl: threads.meta.prev_page_url,
                nextUrl: threads.meta.next_page_url,
            }}
            empty={
                hasFilters ? (
                    <EmptyState
                        icon={Bot}
                        title="No chats match these filters"
                        action={
                            <Button variant="outline" onClick={onClearFilters}>
                                Clear filters
                            </Button>
                        }
                    />
                ) : (
                    <EmptyState
                        icon={Bot}
                        title={
                            handedOver
                                ? 'No chats need the front desk'
                                : 'No AI chats yet'
                        }
                        description={
                            handedOver
                                ? 'When the AI assistant hands a chat over, it shows here.'
                                : 'Guest chats with the AI assistant show here.'
                        }
                    />
                )
            }
        />
    );
}
