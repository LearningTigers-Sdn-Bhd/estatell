import { Head, router } from '@inertiajs/react';
import {
    Ellipsis,
    KeyRound,
    Pencil,
    Send,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { WebhookDeleteDialog } from '@/components/pms/webhooks/webhook-delete-dialog';
import { WebhookDeliveriesTable } from '@/components/pms/webhooks/webhook-deliveries-table';
import type { WebhookDeliveryRow } from '@/components/pms/webhooks/webhook-deliveries-table';
import { WebhookDeliverySheet } from '@/components/pms/webhooks/webhook-delivery-sheet';
import { WebhookEndpointSheet } from '@/components/pms/webhooks/webhook-endpoint-sheet';
import type { WebhookEventOption } from '@/components/pms/webhooks/webhook-endpoint-sheet';
import { WebhookEndpointSwitch } from '@/components/pms/webhooks/webhook-endpoint-switch';
import { WebhookSecretDialog } from '@/components/pms/webhooks/webhook-secret-dialog';
import { endpointWarning } from '@/components/pms/webhooks/webhook-tabs';
import type { WebhookEndpoint } from '@/components/pms/webhooks/webhook-tabs';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { dashboard } from '@/routes/admin';
import { index, rotateSecret, show, test } from '@/routes/admin/webhooks';

type Filters = { event?: string; status?: string };

type Props = {
    endpoint: WebhookEndpoint;
    stats: { sent: number; failed: number; lastDeliveredAt: string | null };
    deliveries: Illuminate.LengthAwarePaginator<number, WebhookDeliveryRow>;
    filters: Filters;
    statuses: EnumOption[];
    selectedDelivery: App.Data.Webhooks.WebhookDeliveryData | null;
    properties: EnumOption[];
    events: WebhookEventOption[];
};

function Stat({ title, value }: { title: string; value: string }) {
    return (
        <Card size="sm">
            <CardHeader>
                <CardDescription>{title}</CardDescription>
                <CardTitle className="text-xl tabular-nums">{value}</CardTitle>
            </CardHeader>
            <CardContent />
        </Card>
    );
}

export default function WebhookShow({
    endpoint,
    stats,
    deliveries,
    filters,
    statuses,
    selectedDelivery,
    properties,
    events,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const warning = endpointWarning(endpoint);
    const endpointEvents = events.filter((event) =>
        endpoint.events.includes(event.value),
    );

    const visit = useCallback(
        (changes: Filters & { delivery?: number | null }, only?: string[]) => {
            const { delivery, ...filterChanges } = changes;
            const next: Filters = { ...filters, ...filterChanges };
            const filter = Object.fromEntries(
                Object.entries(next).filter(([, value]) => value),
            );

            router.get(
                show.url(endpoint.id, {
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
        [filters, endpoint.id],
    );

    const sendTest = (event: string) =>
        router.post(test.url(endpoint.id), { event }, { preserveScroll: true });

    return (
        <>
            <Head title={endpoint.name} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={endpoint.name}
                    description={`${endpoint.url} · ${endpoint.propertyName ?? 'All properties'} · ${endpoint.events.length} events`}
                    actions={
                        <div className="flex items-center gap-2">
                            <WebhookEndpointSwitch endpoint={endpoint} />
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    render={<Button variant="outline" />}
                                >
                                    <Send data-icon="inline-start" />
                                    Send test event
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {endpointEvents.map((event) => (
                                        <DropdownMenuItem
                                            key={event.value}
                                            onClick={() =>
                                                sendTest(event.value)
                                            }
                                        >
                                            <span className="font-mono text-xs">
                                                {event.value}
                                            </span>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <Button
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil data-icon="inline-start" />
                                Edit
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    render={
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label={`More actions for ${endpoint.name}`}
                                        />
                                    }
                                >
                                    <Ellipsis />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onClick={() =>
                                            router.post(
                                                rotateSecret.url(endpoint.id),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <KeyRound />
                                        Rotate secret
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onClick={() => setDeleting(true)}
                                    >
                                        <Trash2 />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    }
                />

                {warning && (
                    <p className="flex items-center gap-2 text-sm text-warning">
                        <TriangleAlert aria-hidden className="size-4" />
                        {warning}
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat title="Last 24 hours" value={`${stats.sent} sent`} />
                    <Stat
                        title="Failed (24 hours)"
                        value={String(stats.failed)}
                    />
                    <Stat
                        title="Last success"
                        value={stats.lastDeliveredAt ?? '—'}
                    />
                </div>

                <section className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-medium">
                            Recent deliveries
                        </h2>
                        <div className="flex flex-wrap gap-2">
                            <EnumSelect
                                options={endpointEvents}
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
                    </div>

                    <WebhookDeliveriesTable
                        deliveries={deliveries}
                        showEndpoint={false}
                        hasFilters={Boolean(filters.event || filters.status)}
                        onClearFilters={() => visit({ event: '', status: '' })}
                        onOpen={(row) =>
                            visit({ delivery: row.id }, ['selectedDelivery'])
                        }
                    />
                </section>
            </div>

            <WebhookDeliverySheet
                delivery={selectedDelivery}
                onClose={() => visit({ delivery: null }, ['selectedDelivery'])}
            />
            <WebhookEndpointSheet
                open={editing}
                onOpenChange={setEditing}
                endpoint={endpoint}
                properties={properties}
                events={events}
            />
            <WebhookDeleteDialog
                endpoint={deleting ? endpoint : null}
                onOpenChange={setDeleting}
            />
            <WebhookSecretDialog />
        </>
    );
}

WebhookShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Webhooks', href: index() },
        { title: props.endpoint.name, href: show(props.endpoint.id) },
    ],
});
