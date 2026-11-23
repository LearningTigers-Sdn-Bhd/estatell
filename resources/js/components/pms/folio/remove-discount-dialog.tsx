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
 * Removes a discount from its charge after a confirmation. The charge stays. A refusal is an error toast.
 */
export function RemoveDiscountDialog({
    owner,
    folioId,
    row,
    onClose,
}: {
    owner: FolioOwner;
    folioId: number;
    row: App.Data.Folio.FolioRowData | null;
    onClose: () => void;
}) {
    return (
        <AlertDialog
            open={row !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Remove {row?.description}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The discount and its tax lines are reversed. The charge
                        stays, and the same discount cannot be applied to it
                        again.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {row?.lineId != null && (
                    <Form
                        {...folioRoutes(owner).discountDestroy(
                            folioId,
                            row.lineId,
                        ).form}
                        options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                        onSuccess={onClose}
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
                                    Remove discount
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}
