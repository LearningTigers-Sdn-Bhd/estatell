import { Form } from '@inertiajs/react';
import { UserX } from 'lucide-react';
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
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/guests/anonymize';

/**
 * Confirms archiving a guest. The button stays disabled until Confirm is typed, because this cannot be undone.
 */
export function AnonymizeGuestDialog({
    guest,
}: {
    guest: App.Data.Guests.GuestData;
}) {
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');
    const confirmed = typed.trim() === 'Confirm';

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setTyped('');
            }}
        >
            <HeaderActionButton
                label="Archive"
                icon={UserX}
                variant="destructive"
                onClick={() => setOpen(true)}
            />
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Archive {guest.fullName}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The name, contact details, address, notes, tags and ID
                        documents with their scans are permanently deleted. The
                        record stays, so stays and payments keep their history.
                        This cannot be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...store.form({ guest: guest.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing }) => (
                        <>
                            <Field>
                                <FieldLabel htmlFor="anonymize-confirm">
                                    Type Confirm to continue
                                </FieldLabel>
                                <Input
                                    id="anonymize-confirm"
                                    value={typed}
                                    autoComplete="off"
                                    placeholder="Confirm"
                                    onChange={(event) =>
                                        setTyped(event.target.value)
                                    }
                                />
                                <FieldDescription>
                                    Type Confirm exactly.
                                </FieldDescription>
                            </Field>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={!confirmed || processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Archive guest
                                </Button>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
