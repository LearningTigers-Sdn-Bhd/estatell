import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

/**
 * Opens on the folio tab right after a checked-out past stay is saved. Settle now goes to the manual folio page;
 * Later (or Esc) closes it and leaves the folio Open with its balance.
 */
export function SettlePastStayDialog({ manualUrl }: { manualUrl: string }) {
    const [open, setOpen] = useState(true);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Settle the folio now?</AlertDialogTitle>
                    <AlertDialogDescription>
                        The stay is saved and checked out. The folio stays open
                        with its balance until you enter the payments and close
                        it. You can do that now or later from the folio tab.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Later</AlertDialogCancel>
                    <AlertDialogAction onClick={() => router.visit(manualUrl)}>
                        Settle now
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
