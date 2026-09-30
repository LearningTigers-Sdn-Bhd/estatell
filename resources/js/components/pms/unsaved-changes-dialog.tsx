import { Save, Trash2 } from 'lucide-react';
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
import type { UnsavedChangesGuard } from '@/hooks/use-unsaved-changes';

/**
 * Asks what to do with unsaved changes when the user tries to leave: Cancel (stay), Discard or Save.
 * `onSave` must save the form and call `guard.proceed()` once the save succeeds.
 */
export function UnsavedChangesDialog({
    guard,
    name,
    saving,
    onSave,
}: {
    guard: UnsavedChangesGuard;
    /** What has unsaved changes, for example "Basic Rate". */
    name: string;
    saving: boolean;
    onSave: () => void;
}) {
    return (
        <AlertDialog
            open={guard.target !== null}
            onOpenChange={(open) => !open && guard.stay()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Save changes to {name}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        You have unsaved changes. Save them, discard them, or
                        cancel to keep editing.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter className="flex-row justify-between sm:justify-between">
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <div className="flex gap-2">
                        <Button variant="destructive" onClick={guard.proceed}>
                            <Trash2 data-icon="inline-start" />
                            Discard
                        </Button>
                        <Button
                            disabled={saving}
                            focusableWhenDisabled
                            onClick={onSave}
                        >
                            {saving ? (
                                <Spinner data-icon="inline-start" />
                            ) : (
                                <Save data-icon="inline-start" />
                            )}
                            Save
                        </Button>
                    </div>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
