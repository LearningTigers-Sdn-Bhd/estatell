import { Form, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    KeyRound,
    Plus,
    ShieldCheck,
    TriangleAlert,
    Webhook,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';
import { revoke, store } from '@/routes/settings/pos-api-clients';

type Client = App.Data.Pos.PosApiClientRowData;
type Issued = { name: string; token: string };

const API_BASE_PATH = '/api/pos/v1';

function formatLastUsed(iso: string | null): string {
    return iso === null
        ? 'Never'
        : new Date(iso).toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}

/** One-time issued token with right-aligned copy button. */
function TokenBox({ token }: { token: string }) {
    const [copied, copy] = useClipboard();

    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-muted/50 p-4">
            <p className="font-mono text-sm break-all text-foreground">
                {token}
            </p>
            <div className="flex justify-end">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="max-sm:min-h-11"
                    onClick={() => copy(token)}
                >
                    {copied === token ? (
                        <Check data-icon="inline-start" />
                    ) : (
                        <Copy data-icon="inline-start" />
                    )}
                    {copied === token ? 'Copied' : 'Copy token'}
                </Button>
            </div>
        </div>
    );
}

export default function PosApiClientsIndex({ clients }: { clients: Client[] }) {
    const [adding, setAdding] = useState(false);
    const [revoking, setRevoking] = useState<Client | null>(null);
    const [issued, setIssued] = useState<Issued | null>(null);
    const [copiedEndpoint, copyEndpoint] = useClipboard();
    const form = useForm({ name: '' });

    // The token arrives once, as flash data on the redirect after "Add API client".
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;

            if (flash?.posToken) {
                setIssued(flash.posToken as Issued);
            }
        });
    }, []);

    const activeCount = useMemo(
        () => clients.filter((client) => client.canRevoke).length,
        [clients],
    );
    const revokedCount = clients.length - activeCount;

    const headerActions = (
        <Button
            type="button"
            className="max-sm:min-h-11"
            onClick={() => setAdding(true)}
        >
            <Plus data-icon="inline-start" />
            Add API client
        </Button>
    );

    return (
        <>
            <PosSettingsShell activeTab="api" actions={headerActions}>
                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <section className="min-w-0 overflow-hidden rounded-xl border bg-card">
                        <header className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/40 px-4 py-3">
                            <span className="font-heading text-sm font-semibold text-foreground">
                                API clients
                            </span>
                            <div className="flex items-center gap-2 tabular-nums">
                                <Badge variant="outline">
                                    {activeCount} active
                                </Badge>
                                {revokedCount > 0 && (
                                    <Badge variant="secondary">
                                        {revokedCount} revoked
                                    </Badge>
                                )}
                            </div>
                        </header>

                        {clients.length === 0 ? (
                            <div className="p-8">
                                <EmptyState
                                    icon={Webhook}
                                    title="No API clients yet"
                                    description="Use Add API client above to issue a scoped Bearer token for an external POS or middleware."
                                />
                            </div>
                        ) : (
                            <>
                                <div className="hidden overflow-x-auto md:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead scope="col">
                                                    Client
                                                </TableHead>
                                                <TableHead scope="col">
                                                    Last used
                                                </TableHead>
                                                <TableHead scope="col">
                                                    Status
                                                </TableHead>
                                                <TableHead
                                                    scope="col"
                                                    className="w-28 text-end"
                                                >
                                                    <span className="sr-only">
                                                        Actions
                                                    </span>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {clients.map((client) => (
                                                <TableRow key={client.id}>
                                                    <TableCell>
                                                        <div className="flex items-center gap-2.5">
                                                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-muted/50 text-foreground">
                                                                <KeyRound
                                                                    aria-hidden="true"
                                                                    className="size-3.5"
                                                                />
                                                            </span>
                                                            <span className="font-medium text-foreground">
                                                                {client.name}
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground tabular-nums">
                                                        {formatLastUsed(
                                                            client.lastUsedAt,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge
                                                            status={{
                                                                value: client.canRevoke
                                                                    ? 'active'
                                                                    : 'revoked',
                                                                label: client.canRevoke
                                                                    ? 'Active'
                                                                    : 'Revoked',
                                                                tone: client.canRevoke
                                                                    ? 'success'
                                                                    : 'neutral',
                                                            }}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-end">
                                                        {client.canRevoke && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                aria-label={`Revoke ${client.name}`}
                                                                onClick={() =>
                                                                    setRevoking(
                                                                        client,
                                                                    )
                                                                }
                                                            >
                                                                Revoke
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                <div className="flex flex-col divide-y md:hidden">
                                    {clients.map((client) => (
                                        <div
                                            key={client.id}
                                            className="flex flex-col gap-3 p-4"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="font-medium text-foreground">
                                                        {client.name}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        Last used:{' '}
                                                        {formatLastUsed(
                                                            client.lastUsedAt,
                                                        )}
                                                    </span>
                                                </div>
                                                <StatusBadge
                                                    status={{
                                                        value: client.canRevoke
                                                            ? 'active'
                                                            : 'revoked',
                                                        label: client.canRevoke
                                                            ? 'Active'
                                                            : 'Revoked',
                                                        tone: client.canRevoke
                                                            ? 'success'
                                                            : 'neutral',
                                                    }}
                                                />
                                            </div>
                                            {client.canRevoke && (
                                                <div className="flex justify-end">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="min-h-11"
                                                        aria-label={`Revoke ${client.name}`}
                                                        onClick={() =>
                                                            setRevoking(client)
                                                        }
                                                    >
                                                        Revoke
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </section>

                    <aside
                        aria-label="POS API integration details"
                        className="flex flex-col gap-4 lg:sticky lg:top-4"
                    >
                        <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
                            <div className="flex items-center gap-2">
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                                <h2 className="font-heading text-sm font-semibold text-foreground">
                                    Connection details
                                </h2>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Base endpoint
                                </span>
                                <div className="flex items-center justify-between gap-2 rounded-lg border bg-muted/40 px-3 py-2">
                                    <code className="font-mono text-xs text-foreground">
                                        {API_BASE_PATH}
                                    </code>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        aria-label="Copy API base path"
                                        onClick={() =>
                                            copyEndpoint(API_BASE_PATH)
                                        }
                                    >
                                        {copiedEndpoint === API_BASE_PATH ? (
                                            <Check />
                                        ) : (
                                            <Copy />
                                        )}
                                    </Button>
                                </div>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Authentication header
                                </span>
                                <code className="rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs text-foreground">
                                    Authorization: Bearer &lt;token&gt;
                                </code>
                            </div>

                            <p className="border-t pt-3 text-xs text-muted-foreground">
                                Tokens are displayed once when created. Revoking
                                a client invalidates its token immediately.
                            </p>
                        </div>
                    </aside>
                </div>
            </PosSettingsShell>

            <FormOverlay
                variant="dialog"
                open={adding}
                onOpenChange={(open) => {
                    setAdding(open);

                    if (!open) {
                        form.reset();
                        form.clearErrors();
                    }
                }}
                title="Add API client"
                description="Name the external POS or integration. A Bearer token will be generated and shown once."
                errors={form.errors}
                processing={form.processing}
                submitLabel="Create client"
                onSubmit={() =>
                    form.post(store.url(), {
                        onSuccess: () => {
                            setAdding(false);
                            form.reset();
                        },
                    })
                }
            >
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="client-name">Client name</FieldLabel>
                    <Input
                        id="client-name"
                        placeholder="e.g. Restaurant POS"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
            </FormOverlay>

            <Dialog
                open={issued !== null}
                onOpenChange={(open) => !open && setIssued(null)}
            >
                <DialogContent>
                    {issued && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Token for {issued.name}
                                </DialogTitle>
                                <DialogDescription>
                                    Save this token in your external POS now.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="flex items-center gap-2.5 rounded-lg bg-warning/10 px-3 py-2.5 text-xs text-warning-text">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-4 shrink-0"
                                />
                                <span>
                                    This token is shown only once and cannot be
                                    viewed again after closing this window.
                                </span>
                            </div>
                            <TokenBox token={issued.token} />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    className="max-sm:min-h-11"
                                    onClick={() => setIssued(null)}
                                >
                                    Done
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
            >
                <AlertDialogContent>
                    {revoking && (
                        <Form
                            {...revoke.form({ client: revoking.id })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setRevoking(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Revoke {revoking.name}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            The external POS stops
                                            authenticating immediately. To
                                            reconnect it later, create a new API
                                            client.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Revoke client
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

PosApiClientsIndex.layout = {
    breadcrumbs: posSettingsBreadcrumbs('api'),
};
