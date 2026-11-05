import { Form, Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Link2, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import BookingApiController from '@/actions/App/Http/Controllers/BookingEngine/BookingApiController';
import { ApiQuickStart } from '@/components/pms/booking-website/api-quick-start';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';
import { CopyField } from '@/components/pms/booking-website/copy-field';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
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

type ReturnAddress = App.Data.BookingEngine.BookingReturnAddressData;
type Confirm = 'replace' | 'revoke' | null;

/** Date and time in the viewer's locale. */
function when(iso: string | null): string {
    return iso === null
        ? 'never'
        : new Date(iso).toLocaleString('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}

export default function BookingWebsiteApi({
    api,
}: {
    api: App.Data.BookingEngine.BookingWebsiteApiData;
}) {
    const [newToken, setNewToken] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<ReturnAddress | null>(null);
    const form = useForm({ url: '' });
    const isFull = api.returnAddresses.length >= api.returnAddressLimit;

    // The plain token arrives once, as flash data on the redirect after Create or Replace.
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;

            if (typeof flash?.apiToken === 'string') {
                setNewToken(flash.apiToken);
            }
        });
    }, []);

    const addButton = (
        <Button
            variant="outline"
            onClick={() => setAdding(true)}
            disabled={isFull}
            focusableWhenDisabled
        >
            <Plus data-icon="inline-start" />
            Add address
        </Button>
    );

    const tokenActions = api.hasToken ? (
        <>
            <Button variant="outline" onClick={() => setConfirm('replace')}>
                Replace token
            </Button>
            <Button variant="outline" onClick={() => setConfirm('revoke')}>
                Revoke
            </Button>
        </>
    ) : (
        <Form
            {...BookingApiController.issueToken.form()}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    disabled={processing}
                    focusableWhenDisabled
                >
                    {processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <KeyRound data-icon="inline-start" />
                    )}
                    Create token
                </Button>
            )}
        </Form>
    );

    return (
        <>
            <Head title="Booking API" />
            <BookingWebsiteShell
                activeTab="api"
                asideLabel="Developer quick start"
                asideWidth="wide"
                aside={<ApiQuickStart docsUrl={api.docsUrl} />}
            >
                <div className="flex flex-col gap-8">
                    <section className="flex flex-col gap-4">
                        <SectionHeader
                            title="API token"
                            description={
                                api.hasToken
                                    ? `Created ${when(api.tokenCreatedAt)}, last used ${when(api.tokenLastUsedAt)}.`
                                    : 'Your website sends the token with every request. You see it once, when you create it.'
                            }
                            actions={tokenActions}
                        />
                        <CopyField
                            id="api_address"
                            label="API address"
                            value={api.apiAddress}
                            description="Your developer starts every request with this address."
                        />
                    </section>

                    <section className="flex flex-col gap-4">
                        <SectionHeader
                            title="Return addresses"
                            description={`Guests come back to these pages after they pay. Any other page is refused, so no one can send a guest to a fake page. Most websites need one or two. You can add up to ${api.returnAddressLimit}, so the list stays easy to check.`}
                            actions={
                                api.returnAddresses.length > 0
                                    ? addButton
                                    : undefined
                            }
                        />
                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Address</TableHead>
                                        <TableHead className="text-right">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {api.returnAddresses.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={2}>
                                                <EmptyState
                                                    icon={Link2}
                                                    title="No return addresses yet"
                                                    description="Add the page your website shows after payment, such as https://www.sandbayresort.com/booking/done."
                                                    action={addButton}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        api.returnAddresses.map((address) => (
                                            <TableRow key={address.id}>
                                                <TableCell className="max-w-0 truncate">
                                                    {address.url}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Remove ${address.url}`}
                                                        onClick={() =>
                                                            setRemoving(address)
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                </div>
            </BookingWebsiteShell>

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
                title="Add return address"
                description="A page on your website. Guests land here after they pay."
                errors={form.errors}
                processing={form.processing}
                submitLabel="Add address"
                onSubmit={() =>
                    form.post(BookingApiController.storeReturnAddress.url(), {
                        preserveScroll: true,
                        onSuccess: () => {
                            setAdding(false);
                            form.reset();
                        },
                    })
                }
            >
                <Field data-invalid={!!form.errors.url}>
                    <FieldLabel htmlFor="return-url">Address</FieldLabel>
                    <Input
                        id="return-url"
                        type="url"
                        maxLength={500}
                        placeholder="e.g. https://www.sandbayresort.com/booking/done"
                        value={form.data.url}
                        onChange={(event) =>
                            form.setData('url', event.target.value)
                        }
                        aria-invalid={!!form.errors.url}
                        autoFocus
                    />
                    <FieldDescription>
                        A full address that starts with https://.
                    </FieldDescription>
                    <FieldError>{form.errors.url}</FieldError>
                </Field>
            </FormOverlay>

            <Dialog
                open={newToken !== null}
                onOpenChange={(open) => !open && setNewToken(null)}
            >
                <DialogContent>
                    {newToken && (
                        <>
                            <DialogHeader>
                                <DialogTitle>Your new API token</DialogTitle>
                                <DialogDescription>
                                    Give it to your developer now. You will not
                                    see it again.
                                </DialogDescription>
                            </DialogHeader>
                            <CopyField
                                id="api_token"
                                label="API token"
                                value={newToken}
                            />
                            <DialogFooter>
                                <Button onClick={() => setNewToken(null)}>
                                    Done
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
            >
                <AlertDialogContent>
                    {confirm && (
                        <Form
                            {...(confirm === 'replace'
                                ? BookingApiController.issueToken.form()
                                : BookingApiController.revokeToken.form())}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setConfirm(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            {confirm === 'replace'
                                                ? 'Replace the API token?'
                                                : 'Revoke the API token?'}
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            {confirm === 'replace'
                                                ? 'The old token stops working at once. Your website cannot take bookings until your developer adds the new one.'
                                                : 'The token stops working at once, and your website cannot take bookings. You can create a new token later.'}
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
                                            {confirm === 'replace'
                                                ? 'Replace token'
                                                : 'Revoke token'}
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <AlertDialogContent>
                    {removing && (
                        <Form
                            {...BookingApiController.destroyReturnAddress.form({
                                bookingReturnAddress: removing.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setRemoving(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Remove {removing.url}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            New payments cannot use this
                                            address. A guest who is paying now
                                            still returns to it.
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
                                            Remove address
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
