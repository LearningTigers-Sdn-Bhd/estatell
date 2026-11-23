import { Form, Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, MoreHorizontal, Plus, Webhook } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
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
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { profile } from '@/routes/settings';
import { index, revoke, store } from '@/routes/settings/pos-api-clients';

type Client = App.Data.Pos.PosApiClientRowData;
type Issued = { name: string; token: string };

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

/** The token in a copyable box. It is shown once. */
function TokenBox({ token }: { token: string }) {
    const [copied, copy] = useClipboard();

    return (
        <div className="flex flex-col gap-3 rounded-lg bg-muted p-4">
            <p className="font-mono text-sm break-all">{token}</p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
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
    );
}

export default function PosApiClientsIndex({ clients }: { clients: Client[] }) {
    const [adding, setAdding] = useState(false);
    const [revoking, setRevoking] = useState<Client | null>(null);
    const [issued, setIssued] = useState<Issued | null>(null);
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

    const addButton = (
        <Button onClick={() => setAdding(true)}>
            <Plus data-icon="inline-start" />
            Add API client
        </Button>
    );

    return (
        <>
            <Head title="POS API access" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS API access"
                    description="Tokens for an external POS or middleware to look up in-house guests and post room charges. Revoke a client to stop it at once."
                    actions={addButton}
                />

                {clients.length === 0 ? (
                    <EmptyState
                        icon={Webhook}
                        title="No API clients yet"
                        description="Add a client to get a token for your POS."
                        action={addButton}
                    />
                ) : (
                    <Card>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Last used</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {clients.map((client) => (
                                        <TableRow key={client.id}>
                                            <TableCell className="font-medium">
                                                {client.name}
                                            </TableCell>
                                            <TableCell>
                                                {when(client.lastUsedAt)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="secondary"
                                                    className={
                                                        client.canRevoke
                                                            ? 'bg-success/10 text-success'
                                                            : 'bg-muted text-muted-foreground'
                                                    }
                                                >
                                                    {client.canRevoke
                                                        ? 'Active'
                                                        : 'Revoked'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {client.canRevoke && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            render={
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon-sm"
                                                                    aria-label={`Actions for ${client.name}`}
                                                                />
                                                            }
                                                        >
                                                            <MoreHorizontal />
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onClick={() =>
                                                                    setRevoking(
                                                                        client,
                                                                    )
                                                                }
                                                            >
                                                                Revoke client
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>

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
                description="You get a token to put in your POS. It is shown once."
                errors={form.errors}
                processing={form.processing}
                submitLabel="Add API client"
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
                    <FieldLabel htmlFor="client-name">Name</FieldLabel>
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
                                    Copy it now. You will not see it again.
                                </DialogDescription>
                            </DialogHeader>
                            <TokenBox token={issued.token} />
                            <DialogFooter>
                                <Button onClick={() => setIssued(null)}>
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
                                            The POS stops working at once. To
                                            connect it again, add a new API
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
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'POS API access', href: index() },
    ],
};
