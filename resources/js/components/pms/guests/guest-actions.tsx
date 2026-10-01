import { Form } from '@inertiajs/react';
import { Ban, ShieldCheck, Star, StarOff } from 'lucide-react';
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
import { HeaderActionButton } from '@/components/pms/guests/header-action-button';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    destroy as unblacklist,
    store as blacklist,
} from '@/routes/guests/blacklist';
import { destroy as unmarkVip, store as markVip } from '@/routes/guests/vip';

type Guest = App.Data.Guests.GuestData;

/**
 * Marks the guest as VIP or takes the mark off. Both are one click, so no confirmation.
 */
export function VipButton({ guest }: { guest: Guest }) {
    const route = guest.isVip
        ? unmarkVip.form({ guest: guest.id })
        : markVip.form({ guest: guest.id });
    const Icon = guest.isVip ? StarOff : Star;

    return (
        <Form {...route} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <HeaderActionButton
                    type="submit"
                    label={guest.isVip ? 'Remove VIP' : 'Mark as VIP'}
                    icon={Icon}
                    iconClassName="text-warning"
                    loading={processing}
                    disabled={processing}
                />
            )}
        </Form>
    );
}

/**
 * Blacklists the guest (a reason is required) or, if already blacklisted, removes them from the blacklist.
 */
export function BlacklistButton({ guest }: { guest: Guest }) {
    return guest.blacklistedAt ? (
        <UnblacklistDialog guest={guest} />
    ) : (
        <BlacklistDialog guest={guest} />
    );
}

function BlacklistDialog({ guest }: { guest: Guest }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <HeaderActionButton
                label="Blacklist"
                icon={Ban}
                iconClassName="text-destructive"
                onClick={() => setOpen(true)}
            />
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Blacklist {guest.fullName}</DialogTitle>
                    <DialogDescription>
                        Staff will see the reason on the guest page. You can
                        remove the guest from the blacklist later.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...blacklist.form({ guest: guest.id })}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field data-invalid={!!errors.reason}>
                                    <FieldLabel htmlFor="blacklist-reason">
                                        Reason
                                    </FieldLabel>
                                    <Textarea
                                        id="blacklist-reason"
                                        name="reason"
                                        maxLength={500}
                                        placeholder="Why is this guest being blacklisted?"
                                        autoFocus
                                        aria-invalid={!!errors.reason}
                                    />
                                    <FieldError>{errors.reason}</FieldError>
                                </Field>
                            </FieldGroup>
                            <DialogFooter>
                                <DialogClose
                                    render={<Button variant="outline" />}
                                >
                                    Cancel
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Blacklist guest
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function UnblacklistDialog({ guest }: { guest: Guest }) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <HeaderActionButton
                label="Remove from blacklist"
                icon={ShieldCheck}
                iconClassName="text-success"
                onClick={() => setOpen(true)}
            />
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Remove {guest.fullName} from the blacklist?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The reason is cleared. The change is recorded in the
                        activity log.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...unblacklist.form({ guest: guest.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
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
                                Remove from blacklist
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
