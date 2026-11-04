import { Form, Head, router, useForm } from '@inertiajs/react';
import { Plus, Tablet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
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
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
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
import { profile } from '@/routes/settings';
import { index, revoke, store } from '@/routes/settings/kiosks';

type Kiosk = App.Data.ApiAccess.KioskData;
type Pairing = { name: string; code: string; expiresAt: string | null };

/** Date and time in the viewer's locale. */
function when(iso: string | null): string {
    return iso === null
        ? '–'
        : new Date(iso).toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}

export default function KiosksIndex({ kiosks }: { kiosks: Kiosk[] }) {
    const [adding, setAdding] = useState(false);
    const [revoking, setRevoking] = useState<Kiosk | null>(null);
    const [pairing, setPairing] = useState<Pairing | null>(null);
    const form = useForm({ name: '' });

    // The pairing code arrives once, as flash data on the redirect after "Add kiosk".
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;

            if (flash?.pairing) {
                setPairing(flash.pairing as Pairing);
            }
        });
    }, []);

    const addButton = (
        <Button onClick={() => setAdding(true)}>
            <Plus data-icon="inline-start" />
            Add kiosk
        </Button>
    );

    return (
        <>
            <Head title="Kiosks" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Kiosks"
                    description="Self check-in machines paired to this property. Revoke a kiosk to stop it at once."
                    actions={addButton}
                />

                {kiosks.length === 0 ? (
                    <EmptyState
                        icon={Tablet}
                        title="No kiosks yet"
                        description="Add a kiosk to get a pairing code for the machine."
                        action={addButton}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Device</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Paired</TableHead>
                                <TableHead>Last used</TableHead>
                                <TableHead className="w-0">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {kiosks.map((kiosk) => (
                                <TableRow key={kiosk.id}>
                                    <TableCell className="font-medium">
                                        {kiosk.name}
                                    </TableCell>
                                    <TableCell>
                                        {kiosk.deviceName ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={kiosk.status} />
                                    </TableCell>
                                    <TableCell>
                                        {when(kiosk.pairedAt)}
                                    </TableCell>
                                    <TableCell>
                                        {when(kiosk.lastUsedAt)}
                                    </TableCell>
                                    <TableCell>
                                        {kiosk.canRevoke && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setRevoking(kiosk)
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
                title="Add kiosk"
                description="You get a pairing code to type into the kiosk. It works once and expires in 10 minutes."
                errors={form.errors}
                processing={form.processing}
                submitLabel="Add kiosk"
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
                    <FieldLabel htmlFor="kiosk-name">Name</FieldLabel>
                    <Input
                        id="kiosk-name"
                        placeholder="e.g. Lobby kiosk"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldDescription>
                        Staff see this name in the list.
                    </FieldDescription>
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
            </FormOverlay>

            <Dialog
                open={pairing !== null}
                onOpenChange={(open) => !open && setPairing(null)}
            >
                <DialogContent>
                    {pairing && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Pairing code for {pairing.name}
                                </DialogTitle>
                                <DialogDescription>
                                    Type this code into the kiosk. It works once
                                    and expires at {when(pairing.expiresAt)}.
                                    You will not see it again.
                                </DialogDescription>
                            </DialogHeader>
                            <p className="text-center font-mono text-4xl font-semibold tracking-widest">
                                {pairing.code}
                            </p>
                            <DialogFooter>
                                <Button onClick={() => setPairing(null)}>
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
                            {...revoke.form({ kiosk: revoking.id })}
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
                                            The kiosk stops working at once and
                                            shows "out of service". To use the
                                            machine again, add it as a new
                                            kiosk.
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
                                            Revoke kiosk
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

KiosksIndex.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Kiosks', href: index() },
    ],
};
