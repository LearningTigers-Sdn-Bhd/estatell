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
import type { RouteDefinition } from '@/wayfinder';

/**
 * Replaces the passwords of one or more users with new temp passwords. They are signed out everywhere.
 * The server skips the users `skippedNote` names. Pass `open` and `onOpenChange` to open it from a menu;
 * otherwise it shows its own Reset password button.
 */
export function ResetTemporaryPassword({
    route,
    userIds,
    target,
    skippedNote,
    variant = 'ghost',
    open: controlledOpen,
    onOpenChange,
    onReset,
}: {
    /** The route that resets the passwords, for example `reset()` from the staff or partner temp password routes. */
    route: RouteDefinition<'post'>;
    userIds: number[];
    /** Who is reset, for the title: a name or "3 staff". */
    target: string;
    /** Who the server skips, as the end of the description. */
    skippedNote: string;
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
                        for 14 days. {skippedNote}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    action={route}
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
