import { Head, router } from '@inertiajs/react';
import { useCallback } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import { WebhookDeliveriesTable } from '@/components/pms/webhooks/webhook-deliveries-table';
import type { WebhookDeliveryRow } from '@/components/pms/webhooks/webhook-deliveries-table';
import { WebhookDeliverySheet } from '@/components/pms/webhooks/webhook-delivery-sheet';
import { webhookTabs } from '@/components/pms/webhooks/webhook-tabs';
import { dashboard } from '@/routes/admin';
import { deliveries as deliveriesRoute, index } from '@/routes/admin/webhooks';

type Filters = { endpoint?: string; event?: string; status?: string };

type Props = {
    deliveries: Illuminate.LengthAwarePaginator<number, WebhookDeliveryRow>;
    filters: Filters;
    endpointOptions: EnumOption[];
    events: EnumOption[];
    statuses: EnumOption[];
    selectedDelivery: App.Data.Webhooks.WebhookDeliveryData | null;
};

export default function WebhookDeliveries({
    deliveries,
    filters,
    endpointOptions,
    events,
    statuses,
    selectedDelivery,
}: Props) {
    const visit = useCallback(
        (changes: Filters & { delivery?: number | null }, only?: string[]) => {
            const { delivery, ...filterChanges } = changes;
            const next: Filters = { ...filters, ...filterChanges };
            const filter = Object.fromEntries(
                Object.entries(next).filter(([, value]) => value),
            );

            router.get(
                deliveriesRoute.url({
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(delivery ? { delivery } : {}),
                    },
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    ...(only ? { only } : {}),
                },
            );
        },
        [filters],
    );

    const hasFilters = Boolean(
        filters.endpoint || filters.event || filters.status,
    );

    return (
        <>
            <Head title="Webhook deliveries" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Webhooks"
                    description="Every event sent to every endpoint in the last 30 days."
                />

                <TabLinks
                    activeTab="deliveries"
                    panelWidth="full"
                    className="gap-4"
                    tabs={webhookTabs()}
                >
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-wrap gap-2">
                            <EnumSelect
                                options={endpointOptions}
                                value={filters.endpoint ?? ''}
                                onValueChange={(endpoint) =>
                                    visit({ endpoint })
                                }
                                allLabel="All endpoints"
                                aria-label="Endpoint"
                                className="w-56"
                            />
                            <EnumSelect
                                options={events}
                                value={filters.event ?? ''}
                                onValueChange={(event) => visit({ event })}
                                allLabel="All events"
                                aria-label="Event"
                                className="w-52"
                            />
                            <EnumSelect
                                options={statuses}
                                value={filters.status ?? ''}
                                onValueChange={(status) => visit({ status })}
                                allLabel="All results"
                                aria-label="Result"
                                className="w-40"
                            />
                        </div>

                        <WebhookDeliveriesTable
                            deliveries={deliveries}
                            showEndpoint
                            hasFilters={hasFilters}
                            onClearFilters={() =>
                                visit({ endpoint: '', event: '', status: '' })
                            }
                            onOpen={(row) =>
                                visit({ delivery: row.id }, [
                                    'selectedDelivery',
                                ])
                            }
                        />
                    </div>
                </TabLinks>
            </div>

            <WebhookDeliverySheet
                delivery={selectedDelivery}
                onClose={() => visit({ delivery: null }, ['selectedDelivery'])}
            />
        </>
    );
}

WebhookDeliveries.layout = {
    breadcrumbs: withTabCrumb(
        [
            { title: 'Admin', href: dashboard() },
            { title: 'Webhooks', href: index() },
        ],
        webhookTabs(),
        'deliveries',
    ),
};
