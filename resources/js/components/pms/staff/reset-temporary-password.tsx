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

type StaffMember = App.Data.Staff.StaffMemberData;

/**
 * Replaces a staff member's password with a new temp password. They are signed out everywhere.
 */
export function ResetTemporaryPassword({
    member,
    onReset,
}: {
    member: StaffMember;
    onReset: (userId: number) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="ghost" size="sm" />}>
                Reset password
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Reset the password of {member.name}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Their current password stops working and they are signed
                        out everywhere. They get a new temp password that works
                        for 14 days.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...reset.form()}
                    transform={(data) => ({ ...data, user_ids: [member.id] })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setOpen(false);
                        onReset(member.id);
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
