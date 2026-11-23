import { Form, useForm } from '@inertiajs/react';
import { Info, Plug, RefreshCw, Users } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

    useEffect(() => {
        form.setData('mappings', initial);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initial]);

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

    const isDisconnected =
        connection === null || connection.status.value === 'disconnected';

    return (
        <PosSettingsShell activeTab="integrations">
            <Card>
                <CardHeader className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <div className="flex items-center gap-2.5">
                            <CardTitle>Loyverse</CardTitle>
                            {!configured ? (
                                <Badge variant="secondary">
                                    Not configured
                                </Badge>
                            ) : isDisconnected ? (
                                <Badge variant="outline">Not connected</Badge>
                            ) : (
                                <StatusBadge status={connection.status} />
                            )}
                        </div>
                        <CardDescription>
                            Sync in-house guests to Loyverse as customers and
                            post Room Charge receipts straight to their folio.
                        </CardDescription>
                    </div>
                    {configured && !isDisconnected && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Form {...refreshCatalog.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={
                                            processing || !connection.connected
                                        }
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <RefreshCw data-icon="inline-start" />
                                        )}
                                        Refresh stores &amp; payment types
                                    </Button>
                                )}
                            </Form>
                            <Form {...syncGuests.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={
                                            processing || !connection.connected
                                        }
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <Users data-icon="inline-start" />
                                        )}
                                        Sync in-house guests
                                    </Button>
                                )}
                            </Form>
                        </div>
                    )}
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    {!configured ? (
                        <EmptyState
                            icon={Plug}
                            title="Loyverse is not configured on this server"
                            description="Ask Estatell support to add the Loyverse app keys before connecting a merchant account."
                        />
                    ) : isDisconnected ? (
                        <EmptyState
                            icon={Plug}
                            title="Connect your Loyverse account"
                            description="Allow Estatell to read your Loyverse stores and payment types and keep a customer for each in-house guest."
                            action={
                                <a
                                    href={connect.url()}
                                    className={buttonVariants()}
                                >
                                    Connect Loyverse
                                </a>
                            }
                        />
                    ) : (
                        <>
                            <div className="grid grid-cols-1 gap-4 rounded-lg border bg-muted/30 p-3.5 sm:grid-cols-3">
                                <div className="flex flex-col gap-0.5">
                                    <span className="text-xs text-muted-foreground">
                                        Merchant ID
                                    </span>
                                    <span className="truncate font-mono text-sm">
                                        {connection.merchantId ?? '—'}
                                    </span>
                                </div>
                                <div className="flex flex-col gap-0.5">
                                    <span className="text-xs text-muted-foreground">
                                        Last refreshed
                                    </span>
                                    <span className="text-sm tabular-nums">
                                        {when(connection.lastSyncedAt)}
                                    </span>
                                </div>
                                <div className="flex flex-col gap-0.5">
                                    <span className="text-xs text-muted-foreground">
                                        In-house guests synced
                                    </span>
                                    <span className="text-sm font-medium tabular-nums">
                                        {guestCount}
                                    </span>
                                </div>
                            </div>

                            {connection.lastError && (
                                <Alert variant="destructive">
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

                            <Alert>
                                <Info aria-hidden />
                                <AlertTitle>When to sync manually</AlertTitle>
                                <AlertDescription>
                                    <ul className="mt-1 flex list-disc flex-col gap-1 pl-4">
                                        <li>
                                            <span className="font-medium text-foreground">
                                                Refresh stores &amp; payment
                                                types
                                            </span>{' '}
                                            after adding or renaming a store or
                                            payment type (such as Room Charge)
                                            in Loyverse Back Office.
                                        </li>
                                        <li>
                                            <span className="font-medium text-foreground">
                                                Sync in-house guests
                                            </span>{' '}
                                            right after connecting for the first
                                            time to backfill guests already
                                            checked in, or if Loyverse customers
                                            look out of date. Future check-ins,
                                            room moves, and check-outs sync
                                            automatically.
                                        </li>
                                    </ul>
                                </AlertDescription>
                            </Alert>

                            <div className="flex justify-end">
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
                        </>
                    )}
                </CardContent>
            </Card>

            {connection?.connected && (
                <Card>
                    <CardHeader>
                        <CardTitle>Stores and Room Charge</CardTitle>
                        <CardDescription>
                            Map each Loyverse store to an Estatell outlet and
                            pick the custom payment type cashiers use for Room
                            Charge. Unmapped stores and cash/card tenders are
                            ignored.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        {catalogue === null ? (
                            <p className="text-sm text-muted-foreground">
                                Click{' '}
                                <span className="font-medium text-foreground">
                                    Refresh stores &amp; payment types
                                </span>{' '}
                                above to load your Loyverse stores.
                            </p>
                        ) : (
                            <>
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
                                                <TableRow key={row.store_id}>
                                                    <TableCell className="font-medium">
                                                        {stores[position]
                                                            ?.name ??
                                                            row.store_id}
                                                    </TableCell>
                                                    <TableCell>
                                                        <EnumSelect
                                                            options={outlets}
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
                                                        <Switch
                                                            checked={row.active}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                patch(
                                                                    position,
                                                                    {
                                                                        active: checked,
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
                                <div className="flex justify-end">
                                    <Button
                                        type="button"
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
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            )}
        </PosSettingsShell>
    );
}

PosIntegrationsIndex.layout = {
    breadcrumbs: posSettingsBreadcrumbs('integrations'),
};
