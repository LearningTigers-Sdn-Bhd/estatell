import { Form, Head, useForm } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    ChevronsDownUp,
    ChevronsUpDown,
    Lock,
    Plus,
    Search,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { SaveBar } from '@/components/pms/save-bar';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
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
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { cn } from '@/lib/utils';
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

function featuresLabel(ticked: string[]) {
    if (ticked.length === 0) {
        return 'All features';
    }

    return ticked.length === 1 ? ticked[0] : `Features · ${ticked.length}`;
}

/** Tick one or more feature groups. Nothing ticked shows every group. Filters in the browser. */
function FeaturesFilter({
    groups,
    value,
    onChange,
}: {
    groups: [string, PermissionOption[]][];
    value: string[];
    onChange: (groups: string[]) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={buttonVariants({ variant: 'outline' })}
                aria-label="Features"
            >
                {featuresLabel(value)}
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                {groups.map(([group, items]) => (
                    <DropdownMenuCheckboxItem
                        key={group}
                        checked={value.includes(group)}
                        onCheckedChange={(checked) =>
                            onChange(
                                groups
                                    .map(([name]) => name)
                                    .filter((name) =>
                                        name === group
                                            ? checked
                                            : value.includes(name),
                                    ),
                            )
                        }
                    >
                        {group}
                        <span className="ml-auto text-muted-foreground tabular-nums">
                            {items.length}
                        </span>
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The editable grid. Remounted (by key) when roles are added, so its form state always has every column.
 * It fills the fixed-height page and is the only scroll container, so the header row, the permission column and
 * the group names stay in place in both directions.
 */
function PermissionMatrix({
    roles,
    permissions,
}: {
    roles: RoleColumn[];
    permissions: PermissionOption[];
}) {
    const [query, setQuery] = useState('');
    const [features, setFeatures] = useState<string[]>([]);
    const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set());
    const form = useForm<{ roles: Grid }>({
        roles: Object.fromEntries(
            roles
                .filter((role) => !role.isLocked)
                .map((role) => [String(role.id), role.permissions]),
        ),
    });
    const guard = useUnsavedChanges(form.isDirty);

    const toggle = (roleId: number, permission: string, granted: boolean) => {
        const current = form.data.roles[roleId] ?? [];

        form.setData('roles', {
            ...form.data.roles,
            [roleId]: granted
                ? [...current, permission]
                : current.filter((name) => name !== permission),
        });
    };

    const toggleGroup = (group: string) =>
        setCollapsed((current) => {
            const next = new Set(current);
            if (!next.delete(group)) {
                next.add(group);
            }

            return next;
        });

    const save = (onSaved?: () => void) =>
        form.put(update.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
            onError: () => guard.stay(),
        });

    const isGranted = (role: RoleColumn, permission: string) =>
        role.isLocked || (form.data.roles[role.id] ?? []).includes(permission);

    const allGroups = groupPermissions(permissions);
    const needle = query.trim().toLowerCase();
    const isSearching = needle !== '';
    const groups = groupPermissions(
        permissions.filter(
            (permission) =>
                (features.length === 0 ||
                    features.includes(permission.group)) &&
                permission.label.toLowerCase().includes(needle),
        ),
    );
    const totals = new Map(allGroups);
    const isOpen = (group: string) => isSearching || !collapsed.has(group);
    const allCollapsed =
        groups.length > 0 && groups.every(([group]) => collapsed.has(group));

    return (
        <div
            className={cn(
                'flex min-h-0 flex-1 flex-col gap-4',
                form.isDirty && 'pb-16',
            )}
        >
            <div className="flex flex-wrap items-center gap-2">
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
                <FeaturesFilter
                    groups={allGroups}
                    value={features}
                    onChange={setFeatures}
                />
                <Button
                    variant="ghost"
                    className="ml-auto"
                    disabled={isSearching || groups.length === 0}
                    onClick={() =>
                        setCollapsed(
                            allCollapsed
                                ? new Set()
                                : new Set(allGroups.map(([group]) => group)),
                        )
                    }
                >
                    {allCollapsed ? (
                        <ChevronsUpDown data-icon="inline-start" />
                    ) : (
                        <ChevronsDownUp data-icon="inline-start" />
                    )}
                    {allCollapsed ? 'Expand all' : 'Collapse all'}
                </Button>
            </div>

            <div className="min-h-0 flex-1 overflow-auto overscroll-contain rounded-xl border">
                <table className="w-full border-separate border-spacing-0 text-sm [&_td]:border-b [&_th]:border-b [&_tr:last-child>*]:border-b-0">
                    <caption className="sr-only">
                        Permissions granted to each role
                    </caption>
                    <TableHeader className="sticky top-0 z-20 bg-background">
                        <TableRow>
                            <TableHead className="sticky left-0 z-10 min-w-64 bg-background px-4">
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
                        {groups.map(([group, items]) => {
                            const open = isOpen(group);
                            const groupPermissionValues = (
                                totals.get(group) ?? items
                            ).map((permission) => permission.value);

                            return (
                                <Fragment key={group}>
                                    <TableRow className="bg-muted hover:bg-muted">
                                        <TableHead
                                            scope="rowgroup"
                                            className="sticky left-0 z-10 h-auto bg-muted px-4 py-2 font-normal text-foreground"
                                        >
                                            <button
                                                type="button"
                                                className="flex items-center gap-2 rounded-sm text-left font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-default [&_svg]:size-4"
                                                aria-expanded={open}
                                                disabled={isSearching}
                                                onClick={() =>
                                                    toggleGroup(group)
                                                }
                                            >
                                                {open ? (
                                                    <ChevronDown aria-hidden />
                                                ) : (
                                                    <ChevronRight aria-hidden />
                                                )}
                                                {group}
                                                <Badge variant="outline">
                                                    {
                                                        groupPermissionValues.length
                                                    }
                                                </Badge>
                                            </button>
                                        </TableHead>
                                        {roles.map((role) => (
                                            <TableCell
                                                key={role.id}
                                                className="py-2 text-center text-xs text-muted-foreground tabular-nums"
                                            >
                                                <span className="sr-only">
                                                    {role.name}:{' '}
                                                </span>
                                                {
                                                    groupPermissionValues.filter(
                                                        (value) =>
                                                            isGranted(
                                                                role,
                                                                value,
                                                            ),
                                                    ).length
                                                }
                                                /{groupPermissionValues.length}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                    {open &&
                                        items.map((permission) => (
                                            <TableRow key={permission.value}>
                                                <TableHead
                                                    scope="row"
                                                    className="sticky left-0 z-10 bg-background px-4 font-normal text-foreground"
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
                                                                checked={isGranted(
                                                                    role,
                                                                    permission.value,
                                                                )}
                                                                disabled={
                                                                    role.isLocked
                                                                }
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
                            );
                        })}
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

            {form.isDirty && (
                <SaveBar
                    name="role permissions"
                    processing={form.processing}
                    onDiscard={() => form.reset()}
                    saveButton={
                        <Button
                            disabled={form.processing}
                            focusableWhenDisabled
                            onClick={() => save()}
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save permissions
                        </Button>
                    }
                />
            )}
            <UnsavedChangesDialog
                guard={guard}
                name="role permissions"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
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

            <div className="flex min-h-0 flex-1 flex-col gap-6 p-4">
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
    variant: 'fixed' as const,
    breadcrumbs: [{ title: 'Roles', href: index() }],
};
