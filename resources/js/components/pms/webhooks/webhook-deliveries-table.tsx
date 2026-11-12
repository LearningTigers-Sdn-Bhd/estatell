import { ListChecks } from 'lucide-react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type WebhookDeliveryRow = App.Data.Webhooks.WebhookDeliveryRowData;

const column = dataTableColumns<WebhookDeliveryRow>();

function columnsFor(showEndpoint: boolean) {
    return [
        column.accessor('createdAt', {
            header: 'Time',
            enableSorting: false,
            cell: (info) => (
                <span className="tabular-nums">{info.getValue()}</span>
            ),
        }),
        ...(showEndpoint
            ? [
                  column.accessor('endpointName', {
                      header: 'Endpoint',
                      enableSorting: false,
                      cell: (info) => (
                          <span className="block max-w-56 truncate">
                              {info.getValue()}
                          </span>
                      ),
                  }),
              ]
            : []),
        column.accessor('event', {
            header: 'Event',
            enableSorting: false,
            cell: (info) => (
                <span className="flex items-center gap-2">
                    <span className="font-mono text-xs">{info.getValue()}</span>
                    {info.row.original.isTest && (
                        <Badge variant="outline">Test</Badge>
                    )}
                </span>
            ),
        }),
        column.accessor('status', {
            header: 'Result',
            enableSorting: false,
            cell: (info) => (
                <span className="flex items-center gap-2">
                    <StatusBadge status={info.getValue()} />
                    {info.row.original.responseStatus !== null && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            HTTP {info.row.original.responseStatus}
                        </span>
                    )}
                </span>
            ),
        }),
        column.accessor('attempts', {
            header: 'Tries',
            enableSorting: false,
            cell: (info) => (
                <span className="tabular-nums">{info.getValue()}</span>
            ),
        }),
    ];
}

/**
 * A server-paginated deliveries list. A row opens the delivery Sheet.
 */
export function WebhookDeliveriesTable({
    deliveries,
    showEndpoint,
    hasFilters,
    onClearFilters,
    onOpen,
}: {
    deliveries: Illuminate.LengthAwarePaginator<number, WebhookDeliveryRow>;
    showEndpoint: boolean;
    hasFilters: boolean;
    onClearFilters: () => void;
    onOpen: (row: WebhookDeliveryRow) => void;
}) {
    return (
        <DataTable
            columns={columnsFor(showEndpoint)}
            data={deliveries.data}
            getRowId={(row) => String(row.id)}
            sort=""
            onSortChange={() => undefined}
            onRowClick={onOpen}
            pagination={{
                currentPage: deliveries.meta.current_page,
                lastPage: deliveries.meta.last_page,
                from: deliveries.meta.from,
                to: deliveries.meta.to,
                total: deliveries.meta.total,
                prevUrl: deliveries.meta.prev_page_url,
                nextUrl: deliveries.meta.next_page_url,
            }}
            empty={
                hasFilters ? (
                    <EmptyState
                        icon={ListChecks}
                        title="No deliveries match these filters"
                        action={
                            <Button variant="outline" onClick={onClearFilters}>
                                Clear filters
                            </Button>
                        }
                    />
                ) : (
                    <EmptyState
                        icon={ListChecks}
                        title="No deliveries yet"
                        description="Deliveries are kept for 30 days."
                    />
                )
            }
        />
    );
}
