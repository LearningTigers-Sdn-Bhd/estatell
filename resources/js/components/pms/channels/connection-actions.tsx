import { Form, Link, router } from '@inertiajs/react';
import { Ellipsis, Pause, Trash2, Download } from 'lucide-react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { connect } from '@/routes/admin/properties/channels';
import {
    activate,
    destroy,
    importMethod,
    map,
    paused,
} from '@/routes/admin/properties/channels/connections';

type OtaRow = App.Data.Channels.ChannelOtaRowData;

/**
 * The buttons of one OTA line: Connect, Continue or Mapping, Activate or Resume, and a menu with Pause, Import future
 * bookings and Disconnect. Activate and Disconnect ask first.
 */
export function ConnectionActions({
    code,
    ota,
}: {
    code: string;
    ota: OtaRow;
}) {
    const [asking, setAsking] = useState<'activate' | 'disconnect' | null>(
        null,
    );
    const connectionId = ota.connectionId;

    if (connectionId === null) {
        return ota.canConnect ? (
            <Link
                href={connect({ property: code, otaChannel: ota.otaChannelId })}
                className={buttonVariants({ variant: 'outline', size: 'sm' })}
            >
                Connect
            </Link>
        ) : null;
    }

    const target = { property: code, connection: connectionId };
    const send = (
        method: 'post' | 'put',
        url: string,
        data: Record<string, boolean> = {},
    ) => router[method](url, data, { preserveScroll: true });

    return (
        <div className="flex items-center justify-end gap-2">
            <Link
                href={map(target)}
                className={buttonVariants({ variant: 'outline', size: 'sm' })}
            >
                {ota.status?.value === 'draft' ? 'Continue' : 'Mapping'}
            </Link>
            {ota.canActivate && (
                <Button size="sm" onClick={() => setAsking('activate')}>
                    Activate
                </Button>
            )}
            {ota.canResume && (
                <Button
                    size="sm"
                    onClick={() =>
                        send('put', paused(target).url, { paused: false })
                    }
                >
                    Resume
                </Button>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button variant="ghost" size="icon-sm" />}
                    aria-label={`Actions for ${ota.name}`}
                >
                    <Ellipsis />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {ota.canPause && (
                        <DropdownMenuItem
                            onClick={() =>
                                send('put', paused(target).url, {
                                    paused: true,
                                })
                            }
                        >
                            <Pause />
                            Pause
                        </DropdownMenuItem>
                    )}
                    {ota.canImport && (
                        <DropdownMenuItem
                            onClick={() =>
                                send('post', importMethod(target).url)
                            }
                        >
                            <Download />
                            Import future bookings
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem
                        variant="destructive"
                        onClick={() => setAsking('disconnect')}
                    >
                        <Trash2 />
                        Disconnect
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <ConfirmDialog
                open={asking === 'activate'}
                onClose={() => setAsking(null)}
                title={`Activate ${ota.name}?`}
                description={`${ota.name} starts receiving availability, rates and restrictions for the next 500 days, and bookings start arriving here. Check that ${ota.name}'s own rates and availability are closed or correct. Channex overwrites them.`}
                action={activate.form(target)}
                label="Activate"
            />
            <ConfirmDialog
                open={asking === 'disconnect'}
                onClose={() => setAsking(null)}
                title={`Disconnect ${ota.name}?`}
                description={`${ota.name} stops receiving availability and rates, and the mapping is removed from Channex and here. Bookings it already sent stay. You can connect it again.`}
                action={destroy.form(target)}
                label="Disconnect"
                destructive
            />
        </div>
    );
}

function ConfirmDialog({
    open,
    onClose,
    title,
    description,
    action,
    label,
    destructive = false,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    action: ReturnType<typeof activate.form>;
    label: string;
    destructive?: boolean;
}) {
    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Not now</AlertDialogCancel>
                            <Button
                                type="submit"
                                variant={
                                    destructive ? 'destructive' : 'default'
                                }
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                {label}
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
