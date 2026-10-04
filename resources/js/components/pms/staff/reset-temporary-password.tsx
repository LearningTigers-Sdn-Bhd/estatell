import { Form } from '@inertiajs/react';
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
import { reset } from '@/routes/settings/staff/temp-passwords';

/**
 * Replaces the passwords of one or more staff with new temp passwords. They are signed out everywhere.
 * The server skips Owners, deactivated staff and yourself. Pass `open` and `onOpenChange` to open it from a menu;
 * otherwise it shows its own Reset password button.
 */
export function ResetTemporaryPassword({
    userIds,
    target,
    variant = 'ghost',
    open: controlledOpen,
    onOpenChange,
    onReset,
}: {
    userIds: number[];
    /** Who is reset, for the title: a name or "3 staff". */
    target: string;
    variant?: 'ghost' | 'outline';
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onReset: (userIds: number[]) => void;
}) {
    const [ownOpen, setOwnOpen] = useState(false);
    const controlled = controlledOpen !== undefined;
    const open = controlled ? controlledOpen : ownOpen;
    const setOpen = (next: boolean) =>
        controlled ? onOpenChange?.(next) : setOwnOpen(next);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            {!controlled && (
                <AlertDialogTrigger
                    render={<Button variant={variant} size="sm" />}
                >
                    Reset password
                </AlertDialogTrigger>
            )}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Reset the password of {target}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Their current password stops working and they are signed
                        out everywhere. They get a new temp password that works
                        for 14 days. Owners and your own account are skipped.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...reset.form()}
                    transform={(data) => ({ ...data, user_ids: userIds })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setOpen(false);
                        onReset(userIds);
                    }}
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
                                Reset password
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
