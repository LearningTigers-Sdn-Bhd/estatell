import { Form } from '@inertiajs/react';
import { Users } from 'lucide-react';
import OwnerSetupLinkController from '@/actions/App/Http/Controllers/Admin/OwnerSetupLinkController';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type StaffMember = App.Data.Platform.AdminPropertyStaffData;

/**
 * The Staff tab body. With `readOnly` (the partner page) there is no Actions column and no Send setup email.
 */
export function PropertyStaff({
    code,
    staff,
    readOnly = false,
}: {
    code: string;
    staff: StaffMember[];
    readOnly?: boolean;
}) {
    if (staff.length === 0) {
        return <EmptyState icon={Users} title="No staff yet" />;
    }

    return (
        <div className="overflow-hidden rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Email verified</TableHead>
                        <TableHead>Created</TableHead>
                        {!readOnly && (
                            <TableHead className="text-right">
                                Actions
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {staff.map((user) => (
                        <TableRow key={user.id}>
                            <TableCell className="font-medium">
                                <span className="flex items-center gap-2">
                                    {user.name}
                                    {user.deactivated && (
                                        <Badge variant="outline">
                                            Deactivated
                                        </Badge>
                                    )}
                                </span>
                            </TableCell>
                            <TableCell>{user.email}</TableCell>
                            <TableCell>
                                {user.role ?? (
                                    <span className="text-muted-foreground">
                                        No role
                                    </span>
                                )}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    variant={
                                        user.verified ? 'secondary' : 'outline'
                                    }
                                >
                                    {user.verified
                                        ? 'Verified'
                                        : 'Not verified'}
                                </Badge>
                            </TableCell>
                            <TableCell className="tabular-nums">
                                <DateText date={user.createdAt} />
                            </TableCell>
                            {!readOnly && (
                                <TableCell className="text-right">
                                    {user.canSendSetupLink && (
                                        <Form
                                            {...OwnerSetupLinkController.form(
                                                code,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                    focusableWhenDisabled
                                                >
                                                    {processing && (
                                                        <Spinner data-icon="inline-start" />
                                                    )}
                                                    Send setup email
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
