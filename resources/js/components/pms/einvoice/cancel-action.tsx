import { Form } from '@inertiajs/react';
import { Ban } from 'lucide-react';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cancel } from '@/routes/finance/e-invoices';
import { type Submission } from '@/components/pms/einvoice/submission';

export function CancelAction({ submission }: { submission: Submission }) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="outline" />}>
                <Ban data-icon="inline-start" />
                Cancel
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Cancel {submission.number}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        LHDN will no longer count it, and the guest link stops
                        working. This cannot be undone.
                        {submission.reservation &&
                            ' You can issue a new e-invoice from the folio afterwards.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...cancel.form({ submission: submission.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field data-invalid={!!errors.reason}>
                                <FieldLabel htmlFor="cancel-reason">
                                    Reason
                                </FieldLabel>
                                <Input
                                    id="cancel-reason"
                                    name="reason"
                                    required
                                    maxLength={500}
                                    placeholder="For example: wrong buyer name"
                                    aria-invalid={!!errors.reason}
                                />
                                <FieldDescription>
                                    LHDN keeps this reason with the e-invoice.
                                </FieldDescription>
                                <FieldError>{errors.reason}</FieldError>
                            </Field>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep it</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Cancel e-invoice
                                </Button>
                            </AlertDialogFooter>
                        </>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
