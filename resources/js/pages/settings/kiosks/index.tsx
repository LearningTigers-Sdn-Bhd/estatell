import { Form, Head, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    CreditCard,
    KeyRound,
    Layers,
    MoreHorizontal,
    Plus,
    Tablet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChoiceCards, type Choice } from '@/components/pms/choice-cards';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import {
    SettingsInput,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { profile } from '@/routes/settings';
import { index, revoke, store, update } from '@/routes/settings/kiosks';

type Kiosk = App.Data.ApiAccess.KioskData;
type Pairing = { name: string; code: string; expiresAt: string | null };

/** What each key mode gives the guest, shown on its card. */
const keyChoices: Record<string, Pick<Choice, 'description' | 'icon'>> = {
    pin: {
        icon: KeyRound,
        description: 'A door code. Nothing to dispense or collect.',
    },
    card: {
        icon: CreditCard,
        description: 'A key card from the slot. Needs a TTLock gateway.',
    },
    both: {
        icon: Layers,
        description: 'A door code and a key card, for every guest.',
    },
};

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

/** How long ago, for the "last seen" line. Older than a week falls back to the date. */
function ago(iso: string | null): string {
    if (iso === null) {
        return 'Never';
    }

    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    if (minutes < 60 * 24) {
        return `${Math.round(minutes / 60)} h ago`;
    }

    return minutes < 60 * 24 * 7
        ? `${Math.round(minutes / 60 / 24)} d ago`
        : when(iso);
}

/** One kiosk: who it is, whether it works, when it was last used, and the way to stop it. */
function KioskCard({
    kiosk,
    onRevoke,
}: {
    kiosk: Kiosk;
    onRevoke: (kiosk: Kiosk) => void;
}) {
    return (
        <Card className={cn(kiosk.status.value === 'revoked' && 'opacity-60')}>
            <CardContent className="flex flex-col gap-4">
                <div className="flex items-start gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                        <Tablet className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{kiosk.name}</p>
                        <p className="truncate text-sm text-muted-foreground">
                            {kiosk.deviceName ?? 'Not paired yet'}
                        </p>
                    </div>
                    {kiosk.canRevoke && (
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                render={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`Actions for ${kiosk.name}`}
                                    />
                                }
                            >
                                <MoreHorizontal />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    variant="destructive"
                                    onClick={() => onRevoke(kiosk)}
                                >
                                    Revoke kiosk
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
                <div className="flex items-center justify-between gap-3 border-t pt-3 text-sm">
                    <StatusBadge status={kiosk.status} />
                    <dl className="text-right text-muted-foreground">
                        <div className="flex justify-end gap-1.5">
                            <dt>Last seen</dt>
                            <dd className="font-medium text-foreground">
                                {ago(kiosk.lastUsedAt)}
                            </dd>
                        </div>
                        <div className="flex justify-end gap-1.5">
                            <dt>Paired</dt>
                            <dd>{when(kiosk.pairedAt)}</dd>
                        </div>
                    </dl>
                </div>
            </CardContent>
        </Card>
    );
}

/** The pairing code in large type, with a copy button. */
function PairingCode({ code }: { code: string }) {
    const [copied, copy] = useClipboard();
    const plain = code.replace('-', '');
    const done = copied === plain;

    return (
        <div className="flex flex-col items-center gap-3 rounded-lg bg-muted py-6">
            <p
                className="font-mono text-4xl font-semibold tracking-[0.3em] tabular-nums"
                aria-label={`Pairing code ${plain.split('').join(' ')}`}
            >
                {code}
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => copy(plain)}
            >
                {done ? (
                    <Check data-icon="inline-start" />
                ) : (
                    <Copy data-icon="inline-start" />
                )}
                {done ? 'Copied' : 'Copy code'}
            </Button>
        </div>
    );
}

export default function KiosksIndex({
    kiosks,
    settings,
    keyModes,
}: {
    kiosks: Kiosk[];
    settings: App.Data.ApiAccess.KioskSettingsData;
    keyModes: { value: string; label: string }[];
}) {
    const [adding, setAdding] = useState(false);
    const [revoking, setRevoking] = useState<Kiosk | null>(null);
    const [pairing, setPairing] = useState<Pairing | null>(null);
    const [keyMode, setKeyMode] = useState<string>(settings.keyMode);
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

    const choices: Choice[] = keyModes.map((mode) => ({
        value: mode.value,
        label: mode.label,
        ...(keyChoices[mode.value] ?? { icon: KeyRound, description: '' }),
    }));

    return (
        <>
            <Head title="Kiosks" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Kiosks"
                    description="Self check-in machines paired to this property. Revoke a kiosk to stop it at once."
                    actions={addButton}
                />

                <Card>
                    <CardContent>
                        <SettingsSection
                            guardUnsavedChanges
                            form={update.form()}
                            title="Kiosk settings"
                            description="How the kiosks of this property behave for guests."
                            contentClassName="grid gap-5 md:grid-cols-2"
                        >
                            {(errors) => (
                                <>
                                    <ChoiceCards
                                        id="key_mode"
                                        name="key_mode"
                                        legend="Room key"
                                        className="md:col-span-2"
                                        columns={3}
                                        choices={choices}
                                        value={keyMode}
                                        onValueChange={setKeyMode}
                                        error={errors.key_mode}
                                    />
                                    <div className="rounded-lg border p-4">
                                        <SettingsInput
                                            name="idle_timeout_seconds"
                                            label="Idle time (seconds)"
                                            type="number"
                                            min={15}
                                            max={600}
                                            value={settings.idleTimeoutSeconds}
                                            description="The screen goes back to the start after this long without a touch."
                                            error={errors.idle_timeout_seconds}
                                        />
                                    </div>
                                    <SettingsSwitch
                                        bordered
                                        centered
                                        name="walk_in_enabled"
                                        label="Walk-in bookings"
                                        checked={settings.walkInEnabled}
                                        description="Guests with no booking can book a room at the kiosk. The room is held until the deposit is paid at the front desk."
                                        error={errors.walk_in_enabled}
                                    />
                                </>
                            )}
                        </SettingsSection>
                    </CardContent>
                </Card>

                {kiosks.length === 0 ? (
                    <EmptyState
                        icon={Tablet}
                        title="No kiosks yet"
                        description="Add a kiosk to get a pairing code for the machine."
                        action={addButton}
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {kiosks.map((kiosk) => (
                            <KioskCard
                                key={kiosk.id}
                                kiosk={kiosk}
                                onRevoke={setRevoking}
                            />
                        ))}
                    </div>
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
                            <PairingCode code={pairing.code} />
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
