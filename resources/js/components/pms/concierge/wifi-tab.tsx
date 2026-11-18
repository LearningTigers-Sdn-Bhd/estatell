import { Form, router, useForm } from '@inertiajs/react';
import { Ellipsis, Pencil, Plus, Star, Trash2, Wifi } from 'lucide-react';
import { useState } from 'react';
import { keepPage, SwitchRow } from '@/components/pms/concierge/content-fields';
import { PanelSection } from '@/components/pms/concierge/guide-layout';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
import { destroy, store, update } from '@/routes/settings/concierge/wifi';
import { update as active } from '@/routes/settings/concierge/wifi/active';

type Network = App.Data.Concierge.ConciergeWifiData;

/**
 * The Wi-Fi networks checked-in guests see. A table with the Hide/Show switch on the left; Add network and the row menu's
 * Edit open a dialog, Set as main saves at once, Delete asks first. A network that is off is staff only but still counts
 * toward `limit`.
 */
export function WifiNetworks({
    networks,
    limit,
}: {
    networks: Network[];
    limit: number;
}) {
    /** The open network dialog: `network` is null when adding. */
    const [dialog, setDialog] = useState<{ network: Network | null } | null>(
        null,
    );
    const [deleting, setDeleting] = useState<Network | null>(null);
    const full = networks.length >= limit;

    return (
        <PanelSection
            title="Guest Wi-Fi"
            description="Only guests who are checked in see these networks. Switch one off to keep it for staff."
            action={
                <div className="flex items-center gap-2">
                    <Badge variant="outline">
                        {networks.length} of {limit}
                    </Badge>
                    <Button
                        type="button"
                        disabled={full}
                        focusableWhenDisabled
                        title={full ? `Up to ${limit} networks` : undefined}
                        onClick={() => setDialog({ network: null })}
                    >
                        <Plus data-icon="inline-start" />
                        Add network
                    </Button>
                </div>
            }
        >
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-24">Hide/Show</TableHead>
                            <TableHead className="w-56">Network</TableHead>
                            <TableHead className="w-48">Password</TableHead>
                            <TableHead>Note</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {networks.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={5}>
                                    <EmptyState
                                        icon={Wifi}
                                        title="No Wi-Fi networks yet"
                                        description="Use Add network so checked-in guests can see its name and password."
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                        {networks.map((network) => (
                            <TableRow key={network.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={network.active}
                                        aria-label={`${network.active ? 'Hide' : 'Show'} ${network.name} ${network.active ? 'from' : 'to'} guests`}
                                        onCheckedChange={(next) =>
                                            router.put(
                                                active.url({
                                                    wifiNetwork: network.id,
                                                }),
                                                { active: next },
                                                keepPage,
                                            )
                                        }
                                    />
                                </TableCell>
                                <TableCell className="whitespace-normal">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {network.name}
                                        </span>
                                        {network.primary && (
                                            <Badge variant="secondary">
                                                Main
                                            </Badge>
                                        )}
                                    </span>
                                </TableCell>
                                <TableCell
                                    className={
                                        network.password
                                            ? 'whitespace-normal'
                                            : 'text-muted-foreground'
                                    }
                                >
                                    {network.password ?? 'Open network'}
                                </TableCell>
                                <TableCell className="text-pretty whitespace-normal text-muted-foreground">
                                    {network.note}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Actions for ${network.name}`}
                                                    />
                                                }
                                            >
                                                <Ellipsis />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setDialog({ network })
                                                    }
                                                >
                                                    <Pencil />
                                                    Edit
                                                </DropdownMenuItem>
                                                {!network.primary && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            router.put(
                                                                update.url({
                                                                    wifiNetwork:
                                                                        network.id,
                                                                }),
                                                                {
                                                                    name: network.name,
                                                                    password:
                                                                        network.password,
                                                                    note: network.note,
                                                                    primary_network: true,
                                                                },
                                                                keepPage,
                                                            )
                                                        }
                                                    >
                                                        <Star />
                                                        Set as main
                                                    </DropdownMenuItem>
                                                )}
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        setDeleting(network)
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
                        ))}
                    </TableBody>
                </Table>
            </div>
            {dialog && (
                <NetworkDialog
                    key={dialog.network?.id ?? 'new'}
                    network={dialog.network}
                    onClose={() => setDialog(null)}
                />
            )}
            <DeleteNetworkDialog
                network={deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            />
        </PanelSection>
    );
}

/** The dialog to add a network (`network` null) or change one. A new network is shown to guests; use the row switch to hide it. */
function NetworkDialog({
    network,
    onClose,
}: {
    network: Network | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: network?.name ?? '',
        password: network?.password ?? '',
        note: network?.note ?? '',
        primary_network: network?.primary ?? false,
    });
    const { data, errors } = form;
    const options = { ...keepPage, onSuccess: onClose };

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => !open && onClose()}
            title={network ? 'Edit network' : 'Add a network'}
            description="Checked-in guests see the network name, password and note."
            errors={errors}
            onSubmit={() =>
                network
                    ? form.put(update.url({ wifiNetwork: network.id }), options)
                    : form.post(store.url(), options)
            }
            submitLabel={network ? 'Save network' : 'Add network'}
            processing={form.processing}
        >
            <Field data-invalid={!!errors.name}>
                <FieldLabel htmlFor="wifi_name">Network name</FieldLabel>
                <Input
                    id="wifi_name"
                    placeholder="e.g. Hotel-Guest"
                    value={data.name}
                    maxLength={100}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!errors.name}
                />
                <FieldError>{errors.name}</FieldError>
            </Field>
            <Field data-invalid={!!errors.password}>
                <FieldLabel htmlFor="wifi_password">
                    Password (optional)
                </FieldLabel>
                <Input
                    id="wifi_password"
                    placeholder="e.g. welcome2026"
                    value={data.password}
                    maxLength={100}
                    autoComplete="off"
                    onChange={(event) =>
                        form.setData('password', event.target.value)
                    }
                    aria-invalid={!!errors.password}
                />
                <FieldError>{errors.password}</FieldError>
            </Field>
            <Field data-invalid={!!errors.note}>
                <FieldLabel htmlFor="wifi_note">Note (optional)</FieldLabel>
                <Input
                    id="wifi_note"
                    placeholder="e.g. Lobby and rooms"
                    value={data.note}
                    maxLength={200}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    aria-invalid={!!errors.note}
                />
                <FieldError>{errors.note}</FieldError>
            </Field>
            <SwitchRow
                id="wifi_primary_network"
                title="Main network"
                body="Listed first. Only one network can be the main one."
                checked={data.primary_network}
                onChange={(checked) => form.setData('primary_network', checked)}
                className="items-center rounded-lg border p-4"
            />
        </FormOverlay>
    );
}

/** Confirms deleting a network. It cannot be undone. */
function DeleteNetworkDialog({
    network,
    onOpenChange,
}: {
    network: Network | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <AlertDialog open={network !== null} onOpenChange={onOpenChange}>
            {network && (
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete {network.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Guests will no longer see this network. This cannot
                            be undone. To keep it for staff, switch it off
                            instead.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...destroy.form({ wifiNetwork: network.id })}
                        options={keepPage}
                        onSuccess={() => onOpenChange(false)}
                        disableWhileProcessing
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={processing}>
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
                                    Delete network
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            )}
        </AlertDialog>
    );
}
