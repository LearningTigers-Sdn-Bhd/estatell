import { Form, Head, useForm } from '@inertiajs/react';
import { Lock, Plus, Search } from 'lucide-react';
import { Fragment, useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index, store, update } from '@/routes/settings/roles';

type RoleColumn = App.Data.Staff.RoleRowData;
type PermissionOption = App.Data.Staff.PermissionOptionData;
type Grid = Record<string, string[]>;

function CreateRoleDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button variant="outline" />}>
                <Plus data-icon="inline-start" />
                New role
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New role</DialogTitle>
                    <DialogDescription>
                        A custom role starts with no permissions. It appears as
                        a new column, ready to tick.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field>
                                <FieldLabel htmlFor="role-name">
                                    Name
                                </FieldLabel>
                                <Input
                                    id="role-name"
                                    name="name"
                                    maxLength={60}
                                    aria-invalid={!!errors.name}
                                    autoFocus
                                />
                                <FieldError>{errors.name}</FieldError>
                            </Field>
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
                                    Create role
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

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

/**
 * The editable grid. Remounted (by key) when roles are added, so its form state always has every column.
 */
function PermissionMatrix({
    roles,
    permissions,
}: {
    roles: RoleColumn[];
    permissions: PermissionOption[];
}) {
    const [query, setQuery] = useState('');
    const form = useForm<{ roles: Grid }>({
        roles: Object.fromEntries(
            roles
                .filter((role) => !role.isLocked)
                .map((role) => [String(role.id), role.permissions]),
        ),
    });

    const toggle = (roleId: number, permission: string, granted: boolean) => {
        const current = form.data.roles[roleId] ?? [];

        form.setData('roles', {
            ...form.data.roles,
            [roleId]: granted
                ? [...current, permission]
                : current.filter((name) => name !== permission),
        });
    };

    const save = () =>
        form.put(update.url(), {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });

    const needle = query.trim().toLowerCase();
    const groups = groupPermissions(
        permissions.filter((permission) =>
            permission.label.toLowerCase().includes(needle),
        ),
    );

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <InputGroup className="sm:w-64">
                    <InputGroupAddon>
                        <Search aria-hidden />
                    </InputGroupAddon>
                    <InputGroupInput
                        type="search"
                        placeholder="Search permissions…"
                        aria-label="Search permissions"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </InputGroup>
                {form.isDirty && (
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => form.reset()}
                        >
                            Discard
                        </Button>
                        <Button
                            disabled={form.processing}
                            focusableWhenDisabled
                            onClick={save}
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save permissions
                        </Button>
                    </div>
                )}
            </div>

            <div className="max-h-dvh overflow-auto rounded-lg border">
                <table className="w-full text-sm">
                    <caption className="sr-only">
                        Permissions granted to each role
                    </caption>
                    <TableHeader className="sticky top-0 z-10 bg-background">
                        <TableRow>
                            <TableHead className="sticky left-0 min-w-56 bg-background">
                                Permission
                            </TableHead>
                            {roles.map((role) => (
                                <TableHead
                                    key={role.id}
                                    className="min-w-32 text-center"
                                >
                                    <span className="flex flex-col items-center gap-0.5 py-1">
                                        <span className="flex items-center gap-1">
                                            {role.isLocked && (
                                                <Lock
                                                    className="size-3"
                                                    aria-label="Locked"
                                                />
                                            )}
                                            {role.name}
                                        </span>
                                        <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                            {role.staffCount} staff
                                            {!role.isDefault && ' · Custom'}
                                        </span>
                                    </span>
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {groups.map(([group, items]) => (
                            <Fragment key={group}>
                                <TableRow className="bg-muted hover:bg-muted">
                                    <TableCell className="sticky left-0 bg-muted text-xs font-medium text-muted-foreground">
                                        {group}
                                    </TableCell>
                                    <TableCell colSpan={roles.length} />
                                </TableRow>
                                {items.map((permission) => (
                                    <TableRow key={permission.value}>
                                        <TableHead
                                            scope="row"
                                            className="sticky left-0 bg-background font-normal text-foreground"
                                        >
                                            {permission.label}
                                        </TableHead>
                                        {roles.map((role) => (
                                            <TableCell
                                                key={role.id}
                                                className="text-center"
                                            >
                                                <span className="flex justify-center">
                                                    <Checkbox
                                                        aria-label={`${permission.label} for ${role.name}`}
                                                        checked={
                                                            role.isLocked ||
                                                            (
                                                                form.data.roles[
                                                                    role.id
                                                                ] ?? []
                                                            ).includes(
                                                                permission.value,
                                                            )
                                                        }
                                                        disabled={role.isLocked}
                                                        onCheckedChange={(
                                                            granted,
                                                        ) =>
                                                            toggle(
                                                                role.id,
                                                                permission.value,
                                                                granted,
                                                            )
                                                        }
                                                    />
                                                </span>
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </Fragment>
                        ))}
                        {groups.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={roles.length + 1}
                                    className="h-24 text-center text-muted-foreground"
                                >
                                    No permissions match that search.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </table>
            </div>
            <FieldError>{form.errors.roles}</FieldError>
        </div>
    );
}

export default function RolesIndex({
    roles,
    permissions,
}: {
    roles: RoleColumn[];
    permissions: PermissionOption[];
}) {
    return (
        <>
            <Head title="Roles" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Roles"
                    description="Each column is a role. Tick what it may do, then save. The Owner role always has every permission."
                    actions={<CreateRoleDialog />}
                />

                <PermissionMatrix
                    key={roles.map((role) => role.id).join('-')}
                    roles={roles}
                    permissions={permissions}
                />
            </div>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [{ title: 'Roles', href: index() }],
};
