import { Form } from '@inertiajs/react';
import { StickyNote } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/guests/notes';

/**
 * Adds an internal note. Notes cannot be edited or deleted once added.
 */
export function AddNoteDialog({ guest }: { guest: App.Data.Guests.GuestData }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button />}>
                <StickyNote data-icon="inline-start" />
                Add note
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add note for {guest.fullName}</DialogTitle>
                    <DialogDescription>
                        Only staff see notes. A note cannot be edited or deleted
                        after it is added.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form({ guest: guest.id })}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field data-invalid={!!errors.body}>
                                    <FieldLabel htmlFor="note-body">
                                        Note
                                    </FieldLabel>
                                    <Textarea
                                        id="note-body"
                                        name="body"
                                        maxLength={2000}
                                        placeholder="Prefers a quiet room on a high floor"
                                        autoFocus
                                        aria-invalid={!!errors.body}
                                    />
                                    <FieldDescription>
                                        For example, a room preference or an
                                        allergy.
                                    </FieldDescription>
                                    <FieldError>{errors.body}</FieldError>
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
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Add note
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
