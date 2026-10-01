import { Form } from '@inertiajs/react';
import { Merge } from 'lucide-react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/guests/merge';

/**
 * Confirms a merge. The guest whose page this is stays, and the duplicate is merged into it.
 */
export function MergeGuestDialog({
    survivor,
    duplicate,
}: {
    survivor: App.Data.Guests.GuestData;
    duplicate: App.Data.Guests.GuestMatchData;
}) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button size="sm" variant="outline" />}>
                <Merge data-icon="inline-start" />
                Merge
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Merge {duplicate.fullName} into {survivor.fullName}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Notes, tags and ID documents move to {survivor.fullName}
                        . Empty details are filled from {duplicate.fullName}.{' '}
                        {duplicate.fullName} is hidden and keeps only the name.
                        This cannot be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...store.form({ guest: survivor.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="duplicate_id"
                                value={duplicate.id}
                            />
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
                                    Merge guests
                                </Button>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
