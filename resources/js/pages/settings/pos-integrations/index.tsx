import { Form, Head, useForm } from '@inertiajs/react';
import { Plug, RefreshCw, Users } from 'lucide-react';
import { useMemo } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { profile } from '@/routes/settings';
import { index } from '@/routes/settings/pos-integrations';
import {
    connect,
    disconnect,
    refreshCatalog,
    syncGuests,
} from '@/routes/settings/pos-integrations/loyverse';
import { update as updateMappings } from '@/routes/settings/pos-integrations/loyverse/mappings';

type Option = { value: string; label: string };
type Connection = {
    status: App.Data.Shared.BadgeData;
    merchantId: string | null;
    lastSyncedAt: string | null;
    lastError: string | null;
    connected: boolean;
};
type Catalogue = {
    stores: { id: string; name: string }[];
    paymentTypes: { id: string; name: string }[];
};
type Mapping = {
    storeId: string;
    outletId: string;
    paymentTypeId: string;
    active: boolean;
};
type Row = {
    store_id: string;
    pos_outlet_id: string;
    payment_type_id: string;
    active: boolean;
};

function when(iso: string | null): string {
    return iso === null
        ? 'Never'
        : new Date(iso).toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}

export default function PosIntegrationsIndex({
    configured,
    connection,
    outlets,
    catalogue,
    mappings,
    guestCount,
}: {
    configured: boolean;
    connection: Connection | null;
    outlets: Option[];
    catalogue: Catalogue | null;
    mappings: Mapping[];
    guestCount: number;
}) {
    const stores = useMemo(() => catalogue?.stores ?? [], [catalogue]);
    const initial: Row[] = useMemo(
        () =>
            stores.map((store) => {
                const saved = mappings.find((m) => m.storeId === store.id);

                return {
                    store_id: store.id,
                    pos_outlet_id: saved?.outletId ?? '',
                    payment_type_id: saved?.paymentTypeId ?? '',
                    active: saved?.active ?? true,
                };
            }),
        [stores, mappings],
    );
    const form = useForm<{ mappings: Row[] }>({ mappings: initial });

    function patch(position: number, changes: Partial<Row>) {
        form.setData(
            'mappings',
            form.data.mappings.map((row, at) =>
                at === position ? { ...row, ...changes } : row,
            ),
        );
    }

    const paymentOptions: Option[] = (catalogue?.paymentTypes ?? []).map(
        (type) => ({ value: type.id, label: type.name }),
    );

    return (
        <>
            <Head title="POS integrations" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS integrations"
                    description="Connect Loyverse so cashiers can bill a meal to a guest's room. In-house guests appear in Loyverse as customers, and Room Charge sales post to the folio."
                />

                {!configured && (
                    <Alert>
                        <AlertTitle>
                            Loyverse is not set up on this server
                        </AlertTitle>
                        <AlertDescription>
                            Ask support to add the Loyverse app keys.
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Loyverse</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        {connection === null ||
                        connection.status.value === 'disconnected' ? (
                            <EmptyState
                                icon={Plug}
                                title="Not connected"
                                description="Allow Estatell to read your Loyverse stores and payment types and to keep a customer for each in-house guest."
                                action={
                                    <a
                                        href={connect.url()}
                                        className={buttonVariants()}
                                        aria-disabled={!configured}
                                    >
                                        Connect Loyverse
                                    </a>
                                }
                            />
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex items-center gap-3">
                                        <StatusBadge
                                            status={connection.status}
                                        />
                                        <span className="text-sm text-muted-foreground">
                                            Last refreshed{' '}
                                            {when(connection.lastSyncedAt)}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Form {...refreshCatalog.form()}>
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={
                                                        processing ||
                                                        !connection.connected
                                                    }
                                                    focusableWhenDisabled
                                                >
                                                    {processing ? (
                                                        <Spinner data-icon="inline-start" />
                                                    ) : (
                                                        <RefreshCw data-icon="inline-start" />
                                                    )}
                                                    Refresh stores and payment
                                                    types
                                                </Button>
                                            )}
                                        </Form>
                                        <Form {...syncGuests.form()}>
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={
                                                        processing ||
                                                        !connection.connected
                                                    }
                                                    focusableWhenDisabled
                                                >
                                                    {processing ? (
                                                        <Spinner data-icon="inline-start" />
                                                    ) : (
                                                        <Users data-icon="inline-start" />
                                                    )}
                                                    Sync all in-house guests now
                                                </Button>
                                            )}
                                        </Form>
                                        <Form {...disconnect.form()}>
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    disabled={processing}
                                                    focusableWhenDisabled
                                                >
                                                    Disconnect
                                                </Button>
                                            )}
                                        </Form>
                                    </div>
                                </div>

                                {connection.lastError && (
                                    <Alert>
                                        <AlertTitle>
                                            {connection.lastError}
                                        </AlertTitle>
                                        <AlertDescription>
                                            <a
                                                href={connect.url()}
                                                className="underline"
                                            >
                                                Connect Loyverse again
                                            </a>
                                        </AlertDescription>
                                    </Alert>
                                )}

                                <p className="text-sm text-muted-foreground">
                                    {guestCount} in-house{' '}
                                    {guestCount === 1
                                        ? 'guest is'
                                        : 'guests are'}{' '}
                                    in Loyverse as customers.
                                </p>
                            </>
                        )}
                    </CardContent>
                </Card>

                {connection?.connected && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Stores and Room Charge</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {catalogue === null ? (
                                <p className="text-sm text-muted-foreground">
                                    Refresh the stores and payment types, then
                                    choose the outlet and the Room Charge
                                    payment type for each store. Stores you
                                    leave empty are ignored.
                                </p>
                            ) : (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        In Loyverse, create a payment type such
                                        as &quot;Room Charge&quot;, then pick it
                                        here. Cashiers attach the room customer
                                        and tender the bill with it. Cash and
                                        card payments are never posted.
                                    </p>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    Loyverse store
                                                </TableHead>
                                                <TableHead>Outlet</TableHead>
                                                <TableHead>
                                                    Room Charge payment type
                                                </TableHead>
                                                <TableHead>Active</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {form.data.mappings.map(
                                                (row, position) => (
                                                    <TableRow
                                                        key={row.store_id}
                                                    >
                                                        <TableCell className="font-medium">
                                                            {stores[position]
                                                                ?.name ??
                                                                row.store_id}
                                                        </TableCell>
                                                        <TableCell>
                                                            <EnumSelect
                                                                options={
                                                                    outlets
                                                                }
                                                                value={
                                                                    row.pos_outlet_id
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    patch(
                                                                        position,
                                                                        {
                                                                            pos_outlet_id:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                                allLabel="Not connected"
                                                                aria-label={`Outlet for ${stores[position]?.name ?? row.store_id}`}
                                                                className="w-48"
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            <EnumSelect
                                                                options={
                                                                    paymentOptions
                                                                }
                                                                value={
                                                                    row.payment_type_id
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    patch(
                                                                        position,
                                                                        {
                                                                            payment_type_id:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                                allLabel="None"
                                                                aria-label={`Payment type for ${stores[position]?.name ?? row.store_id}`}
                                                                className="w-48"
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            <Checkbox
                                                                checked={
                                                                    row.active
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    patch(
                                                                        position,
                                                                        {
                                                                            active:
                                                                                checked ===
                                                                                true,
                                                                        },
                                                                    )
                                                                }
                                                                aria-label={`Active for ${stores[position]?.name ?? row.store_id}`}
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                    <Button
                                        type="button"
                                        className="self-start"
                                        disabled={form.processing}
                                        focusableWhenDisabled
                                        onClick={() =>
                                            form.put(updateMappings.url(), {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        {form.processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Save mappings
                                    </Button>
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

PosIntegrationsIndex.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'POS integrations', href: index() },
    ],
};
