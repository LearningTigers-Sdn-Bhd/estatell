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
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/settings/staff/access';

type Colleague = App.Data.Staff.GroupColleagueData;
type RoleOption = App.Data.Staff.RoleOptionData;

/**
 * Settings › Staff › Add from another property: give a staff member of another property in the group access here,
 * with a role here. Their home property, role and password stay as they are. See docs/03-staff/04-property-groups.md.
 */
export function AddFromGroupDialog({
    colleagues,
    roles,
    open,
    onOpenChange,
}: {
    colleagues: Colleague[];
    roles: RoleOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const options = colleagues.map((colleague) => ({
        value: String(colleague.id),
        label: `${colleague.name} · ${colleague.homeProperty}`,
    }));

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add from another property</DialogTitle>
                    <DialogDescription>
                        Let staff of another property in your group open this
                        one too. They sign in with their own account and switch
                        properties from the top bar.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field data-invalid={!!errors.user_id}>
                                    <FieldLabel htmlFor="group-staff">
                                        Staff member
                                    </FieldLabel>
                                    <EnumSelect
                                        id="group-staff"
                                        name="user_id"
                                        options={options}
                                        placeholder="Choose a staff member"
                                        aria-invalid={!!errors.user_id}
                                    />
                                    <FieldError>{errors.user_id}</FieldError>
                                </Field>
                                <Field data-invalid={!!errors.role_id}>
                                    <FieldLabel htmlFor="group-role">
                                        Role here
                                    </FieldLabel>
                                    <EnumSelect
                                        id="group-role"
                                        name="role_id"
                                        options={roles}
                                        placeholder="Choose a role"
                                        aria-invalid={!!errors.role_id}
                                    />
                                    <FieldDescription>
                                        Their role at their own property does
                                        not change.
                                    </FieldDescription>
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
                                    Give access
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
