import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { edit, index, update } from '@/routes/settings/roles';

type Role = App.Data.Staff.RoleData;
type PermissionOption = App.Data.Staff.PermissionOptionData;

function groupPermissions(permissions: PermissionOption[]) {
    const groups = new Map<string, PermissionOption[]>();

    for (const permission of permissions) {
        groups.set(permission.group, [
            ...(groups.get(permission.group) ?? []),
            permission,
        ]);
    }

    return [...groups.entries()];
}

export default function RoleEdit({
    role,
    permissions,
}: {
    role: Role;
    permissions: PermissionOption[];
}) {
    const groups = groupPermissions(permissions);

    return (
        <>
            <Head title={role.name} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={role.name}
                    back={{ href: index(), label: 'Roles' }}
                    badge={
                        <Badge
                            variant={role.isDefault ? 'secondary' : 'outline'}
                        >
                            {role.isDefault ? 'Default' : 'Custom'}
                        </Badge>
                    }
                />

                <Form
                    {...update.form({ role: role.id })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <Card>
                            <CardHeader>
                                <CardTitle>Permissions</CardTitle>
                                <CardDescription>
                                    {role.isLocked
                                        ? 'The Owner role always has every permission. It cannot be changed.'
                                        : 'Staff with this role can do what is ticked here.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <FieldGroup>
                                    {groups.map(([group, items]) => (
                                        <FieldSet key={group}>
                                            <FieldLegend variant="label">
                                                {group}
                                            </FieldLegend>
                                            <FieldGroup className="gap-3">
                                                {items.map((permission) => (
                                                    <Field
                                                        key={permission.value}
                                                        orientation="horizontal"
                                                    >
                                                        <Checkbox
                                                            id={`permission-${permission.value}`}
                                                            name="permissions[]"
                                                            value={
                                                                permission.value
                                                            }
                                                            defaultChecked={
                                                                role.isLocked ||
                                                                role.permissions.includes(
                                                                    permission.value,
                                                                )
                                                            }
                                                            disabled={
                                                                role.isLocked
                                                            }
                                                        />
                                                        <FieldLabel
                                                            htmlFor={`permission-${permission.value}`}
                                                            className="font-normal"
                                                        >
                                                            {permission.label}
                                                        </FieldLabel>
                                                    </Field>
                                                ))}
                                            </FieldGroup>
                                        </FieldSet>
                                    ))}
                                </FieldGroup>
                                <FieldError>{errors.permissions}</FieldError>
                            </CardContent>
                            {!role.isLocked && (
                                <CardFooter className="justify-end">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Save permissions
                                    </Button>
                                </CardFooter>
                            )}
                        </Card>
                    )}
                </Form>
            </div>
        </>
    );
}

RoleEdit.layout = (props: { role: Role }) => ({
    breadcrumbs: [
        { title: 'Roles', href: index() },
        { title: props.role.name, href: edit({ role: props.role.id }) },
    ],
});
