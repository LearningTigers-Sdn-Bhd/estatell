import { Head, Link } from '@inertiajs/react';
import {
    Ellipsis,
    Pencil,
    Plus,
    Trash2,
    TriangleAlert,
    Webhook,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import { WebhookDeleteDialog } from '@/components/pms/webhooks/webhook-delete-dialog';
import { WebhookEndpointSheet } from '@/components/pms/webhooks/webhook-endpoint-sheet';
import type { WebhookEventOption } from '@/components/pms/webhooks/webhook-endpoint-sheet';
import { WebhookEndpointSwitch } from '@/components/pms/webhooks/webhook-endpoint-switch';
import {
    endpointState,
    endpointWarning,
    webhookTabs,
} from '@/components/pms/webhooks/webhook-tabs';
import type {
    EndpointState,
    WebhookEndpoint,
} from '@/components/pms/webhooks/webhook-tabs';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/webhooks';

type Props = {
    endpoints: WebhookEndpoint[];
    properties: EnumOption[];
    events: WebhookEventOption[];
};

const stateOptions: EnumOption[] = [
    { value: 'active', label: 'Active' },
    { value: 'off', label: 'Off' },
    { value: 'disabled', label: 'Disabled' },
    { value: 'failing', label: 'Failing' },
];

export default function WebhooksIndex({
    endpoints,
    properties,
    events,
}: Props) {
    const [search, setSearch] = useState('');
    const [property, setProperty] = useState('');
    const [state, setState] = useState('');
    const [editing, setEditing] = useState<WebhookEndpoint | null>(null);
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<WebhookEndpoint | null>(null);

    const needle = search.trim().toLowerCase();
    const shown = endpoints.filter(
        (endpoint) =>
            (needle === '' ||
                endpoint.name.toLowerCase().includes(needle) ||
                endpoint.url.toLowerCase().includes(needle)) &&
            (property === '' ||
                (property === 'all'
                    ? endpoint.propertyId === null
                    : String(endpoint.propertyId) === property)) &&
            (state === '' ||
                endpointState(endpoint) === (state as EndpointState)),
    );

    return (
        <>
            <Head title="Webhooks" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Webhooks"
                    description="Send events to n8n or other tools when something happens in Estatell."
                    actions={
                        <Button onClick={() => setAdding(true)}>
                            <Plus data-icon="inline-start" />
                            Add endpoint
                        </Button>
                    }
                />

                <TabLinks
                    activeTab="endpoints"
                    panelWidth="full"
                    className="gap-4"
                    tabs={webhookTabs()}
                >
                    <div className="flex flex-col gap-4">
                        <FilterBar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder="Search name or URL"
                        >
                            <EnumSelect
                                options={[
                                    { value: 'all', label: 'Every property' },
                                    ...properties,
                                ]}
                                value={property}
                                onValueChange={setProperty}
                                allLabel="All scopes"
                                aria-label="Property"
                                className="w-52"
                            />
                            <EnumSelect
                                options={stateOptions}
                                value={state}
                                onValueChange={setState}
                                allLabel="All states"
                                aria-label="State"
                                className="w-40"
                            />
                        </FilterBar>

                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>On</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>URL</TableHead>
                                        <TableHead>Scope</TableHead>
                                        <TableHead className="text-right">
                                            Events
                                        </TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shown.length === 0 && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell colSpan={6}>
                                                <EmptyState
                                                    icon={Webhook}
                                                    title={
                                                        endpoints.length === 0
                                                            ? 'No endpoints yet'
                                                            : 'No endpoints match these filters'
                                                    }
                                                    description={
                                                        endpoints.length === 0
                                                            ? 'Add the URL of an n8n Webhook node to start sending events.'
                                                            : undefined
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {shown.map((endpoint) => {
                                        const warning =
                                            endpointWarning(endpoint);

                                        return (
                                            <TableRow key={endpoint.id}>
                                                <TableCell className="align-top">
                                                    <WebhookEndpointSwitch
                                                        endpoint={endpoint}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex min-w-0 flex-col gap-0.5">
                                                        <Link
                                                            href={show(
                                                                endpoint.id,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {endpoint.name}
                                                        </Link>
                                                        {warning && (
                                                            <span className="flex items-center gap-1 text-xs text-warning">
                                                                <TriangleAlert
                                                                    aria-hidden
                                                                    className="size-3"
                                                                />
                                                                {warning}
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="block max-w-72 truncate font-mono text-xs">
                                                        {endpoint.url}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {endpoint.propertyName ??
                                                        'All properties'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {endpoint.events.length}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex justify-end">
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                render={
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon-sm"
                                                                        aria-label={`Actions for ${endpoint.name}`}
                                                                    />
                                                                }
                                                            >
                                                                <Ellipsis />
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        setEditing(
                                                                            endpoint,
                                                                        )
                                                                    }
                                                                >
                                                                    <Pencil />
                                                                    Edit
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onClick={() =>
                                                                        setDeleting(
                                                                            endpoint,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 />
                                                                    Delete
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </TabLinks>
            </div>

            <WebhookEndpointSheet
                open={adding || editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setAdding(false);
                        setEditing(null);
                    }
                }}
                endpoint={editing}
                properties={properties}
                events={events}
            />
            <WebhookDeleteDialog
                endpoint={deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            />
        </>
    );
}

WebhooksIndex.layout = {
    breadcrumbs: withTabCrumb(
        [
            { title: 'Admin', href: dashboard() },
            { title: 'Webhooks', href: index() },
        ],
        webhookTabs(),
        'endpoints',
    ),
};
