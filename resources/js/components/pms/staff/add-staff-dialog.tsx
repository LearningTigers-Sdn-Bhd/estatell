import { Form } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/settings/staff';

type RoleOption = App.Data.Staff.RoleOptionData;

/**
 * Adds one staff member with a temp password. On success the server flashes `staffAddedId`,
 * which the caller uses to open the sign-in message.
 */
export function AddStaffDialog({
    roles,
    open,
    onOpenChange,
    onAdded,
}: {
    roles: RoleOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAdded: (userId: number) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add staff with a temp password</DialogTitle>
                    <DialogDescription>
                        No email is sent. You get a sign-in message to pass on.
                        They set their own password at first sign-in.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={(page) => {
                        onOpenChange(false);

                        const userId = (
                            page.flash as { staffAddedId?: number } | undefined
                        )?.staffAddedId;

                        if (userId) {
                            onAdded(userId);
                        }
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field>
                                    <FieldLabel htmlFor="add-name">
                                        Name
                                    </FieldLabel>
                                    <Input
                                        id="add-name"
                                        name="name"
                                        autoComplete="off"
                                        aria-invalid={!!errors.name}
                                        autoFocus
                                    />
                                    <FieldError>{errors.name}</FieldError>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="add-email">
                                        Email
                                    </FieldLabel>
                                    <Input
                                        id="add-email"
                                        name="email"
                                        type="email"
                                        autoComplete="off"
                                        aria-invalid={!!errors.email}
                                    />
                                    <FieldError>{errors.email}</FieldError>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="add-role">
                                        Role
                                    </FieldLabel>
                                    <EnumSelect
                                        id="add-role"
                                        name="role_id"
                                        options={roles}
                                        aria-invalid={!!errors.role_id}
                                    />
                                    <FieldError>{errors.role_id}</FieldError>
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
                                    Add staff
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
