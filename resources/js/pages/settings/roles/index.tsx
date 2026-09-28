import { Form, Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { edit, index, store } from '@/routes/settings/roles';

type RoleRow = App.Data.Staff.RoleRowData;

function CreateRoleDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button />}>
                <Plus data-icon="inline-start" />
                New role
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New role</DialogTitle>
                    <DialogDescription>
                        A custom role starts with no permissions. You choose
                        them on the next page.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
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

export default function RolesIndex({ roles }: { roles: RoleRow[] }) {
    return (
        <>
            <Head title="Roles" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Roles"
                    description="What each role can do in this property."
                    actions={<CreateRoleDialog />}
                />

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Role</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">
                                    Active staff
                                </TableHead>
                                <TableHead className="text-right">
                                    Permissions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roles.map((role) => (
                                <TableRow key={role.id}>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={edit({ role: role.id })}
                                            className="hover:underline"
                                        >
                                            {role.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                role.isDefault
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {role.isDefault
                                                ? 'Default'
                                                : 'Custom'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {role.staffCount}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {role.isLocked
                                            ? 'All'
                                            : role.permissionCount}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [{ title: 'Roles', href: index() }],
};
