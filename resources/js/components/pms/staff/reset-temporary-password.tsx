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
 * The server skips Owners, deactivated staff and yourself.
 */
export function ResetTemporaryPassword({
    userIds,
    target,
    variant = 'ghost',
    onReset,
}: {
    userIds: number[];
    /** Who is reset, for the title: a name or "3 staff". */
    target: string;
    variant?: 'ghost' | 'outline';
    onReset: (userIds: number[]) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant={variant} size="sm" />}>
                Reset password
            </AlertDialogTrigger>
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
