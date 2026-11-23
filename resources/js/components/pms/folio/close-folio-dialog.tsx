import { Form } from '@inertiajs/react';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
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
import { Spinner } from '@/components/ui/spinner';
import { folioRoutes } from '@/components/pms/folio/folio-owner';
import type { FolioOwner } from '@/components/pms/folio/folio-owner';

/**
 * Closes a folio after a confirmation. Only a balance of 0 closes, and closing issues the invoice number. A refusal
 * is an error toast.
 */
export function CloseFolioDialog({
    open,
    onOpenChange,
    owner,
    folio,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    owner: FolioOwner;
    folio: App.Data.Folio.FolioData;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Close {folio.name}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        The folio needs a balance of 0. Closing issues its
                        invoice number and stops new postings. You can reopen it
                        later, and the invoice number stays.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...folioRoutes(owner).close(Number(folio.key)).form}
                    options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <Button
                                type="submit"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Close folio
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
