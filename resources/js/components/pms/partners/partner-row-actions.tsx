import { Form, router } from '@inertiajs/react';
import { Ban, Copy, Ellipsis, KeyRound, Pause, Play, Send } from 'lucide-react';
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
import { ResetTemporaryPassword } from '@/components/pms/temporary-passwords/reset-temporary-password';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { reactivate, suspend } from '@/routes/admin/partners';
import {
    destroy as revokeInvitation,
    resend as resendInvitation,
} from '@/routes/admin/partners/invitations';
import { reset } from '@/routes/admin/partners/temporary-passwords';

type Partner = Pick<
    App.Data.Platform.AdminPartnerRowData,
    'id' | 'name' | 'userId' | 'temporaryPasswordExpiresAt'
>;

function RowActionsTrigger({ name }: { name: string }) {
    return (
        <DropdownMenuTrigger
            render={<Button variant="ghost" size="icon-sm" />}
            aria-label={`Actions for ${name}`}
        >
            <Ellipsis />
        </DropdownMenuTrigger>
    );
}

export function ActivePartnerActions({
    partner,
    onShowMessage,
}: {
    partner: Partner;
    onShowMessage: (userIds: number[]) => void;
}) {
    const [dialog, setDialog] = useState<'reset' | 'suspend' | null>(null);
    const canCopy =
        partner.temporaryPasswordExpiresAt !== null &&
        new Date(partner.temporaryPasswordExpiresAt).getTime() > Date.now();

    return (
        <>
            <DropdownMenu>
                <RowActionsTrigger name={partner.name} />
                <DropdownMenuContent align="end" className="w-52">
                    {canCopy && (
                        <DropdownMenuItem
                            onClick={() => onShowMessage([partner.userId])}
                        >
                            <Copy />
                            Copy message
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onClick={() => setDialog('reset')}>
                        <KeyRound />
                        Reset temp password
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onClick={() => setDialog('suspend')}
                    >
                        <Pause />
                        Suspend
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <ResetTemporaryPassword
                route={reset()}
                skippedNote="Suspended partners are skipped."
                userIds={[partner.userId]}
                target={partner.name}
                open={dialog === 'reset'}
                onOpenChange={(open) => !open && setDialog(null)}
                onReset={onShowMessage}
            />
            <AlertDialog
                open={dialog === 'suspend'}
                onOpenChange={(open) => !open && setDialog(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Suspend {partner.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            They can&apos;t sign in. Their properties keep
                            running.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...suspend.form({ partner: partner.id })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setDialog(null)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Suspend
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

export function SuspendedPartnerActions({ partner }: { partner: Partner }) {
    return (
        <DropdownMenu>
            <RowActionsTrigger name={partner.name} />
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuItem
                    onClick={() =>
                        router.post(
                            reactivate.url({ partner: partner.id }),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <Play />
                    Reactivate
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

type Invitation = Pick<
    App.Data.Platform.AdminPartnerInvitationRowData,
    'id' | 'email' | 'partnerName' | 'codePrefix'
>;

/**
 * Row actions on the Invited tab. Resend needs no confirm. Revoke confirms, because the link stops working and the
 * prefix becomes free.
 */
export function InvitedPartnerActions({
    invitation,
}: {
    invitation: Invitation;
}) {
    const [revoking, setRevoking] = useState(false);

    return (
        <>
            <DropdownMenu>
                <RowActionsTrigger name={invitation.partnerName} />
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem
                        onClick={() =>
                            router.post(
                                resendInvitation.url({
                                    partnerInvitation: invitation.id,
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Send />
                        Resend
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onClick={() => setRevoking(true)}
                    >
                        <Ban />
                        Revoke
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <AlertDialog
                open={revoking}
                onOpenChange={(open) => !open && setRevoking(false)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Revoke the invitation to {invitation.email}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The link stops working. The prefix{' '}
                            {invitation.codePrefix} is free for another partner.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...revokeInvitation.form({
                            partnerInvitation: invitation.id,
                        })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setRevoking(false)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Revoke invitation
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
