import { Form, Head } from '@inertiajs/react';
import { Mail, UserMinus, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks } from '@/components/pms/tab-links';
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
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
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
import {
    deactivate,
    deactivated,
    index,
    invited,
    reactivate,
} from '@/routes/settings/staff';
import { destroy, resend, store } from '@/routes/settings/staff/invitations';

type StaffMember = App.Data.Staff.StaffMemberData;
type Invitation = App.Data.Staff.StaffInvitationData;
type RoleOption = App.Data.Staff.RoleOptionData;
type Tab = 'active' | 'invited' | 'deactivated';

function InviteStaffDialog({ roles }: { roles: RoleOption[] }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button />}>
                <UserPlus data-icon="inline-start" />
                Invite staff
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Invite staff</DialogTitle>
                    <DialogDescription>
                        They get an email with a link to set their name and
                        password. The link works for 7 days.
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
                            <FieldGroup>
                                <Field>
                                    <FieldLabel htmlFor="invite-email">
                                        Email
                                    </FieldLabel>
                                    <Input
                                        id="invite-email"
                                        name="email"
                                        type="email"
                                        autoComplete="off"
                                        aria-invalid={!!errors.email}
                                        autoFocus
                                    />
                                    <FieldError>{errors.email}</FieldError>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="invite-role">
                                        Role
                                    </FieldLabel>
                                    <EnumSelect
                                        id="invite-role"
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
                                    Send invitation
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeactivateStaff({ member }: { member: StaffMember }) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="ghost" size="sm" />}>
                Deactivate
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Deactivate {member.name}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        They are signed out and cannot sign in until you
                        reactivate them. Their role and history stay.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...deactivate.form({ user: member.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
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
                                Deactivate
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function ActiveStaff({
    staff,
    canManage,
}: {
    staff: StaffMember[];
    canManage: boolean;
}) {
    return (
        <div className="overflow-hidden rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Joined</TableHead>
                        {canManage && (
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {staff.map((member) => (
                        <TableRow key={member.id}>
                            <TableCell className="font-medium">
                                <span className="flex items-center gap-2">
                                    {member.name}
                                    {member.isYou && (
                                        <Badge variant="outline">You</Badge>
                                    )}
                                </span>
                            </TableCell>
                            <TableCell>{member.email}</TableCell>
                            <TableCell>
                                {member.role ?? (
                                    <span className="text-muted-foreground">
                                        No role
                                    </span>
                                )}
                            </TableCell>
                            <TableCell>
                                <DateText date={member.joinedAt} />
                            </TableCell>
                            {canManage && (
                                <TableCell>
                                    <div className="flex justify-end">
                                        {!member.isYou && (
                                            <DeactivateStaff member={member} />
                                        )}
                                    </div>
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function DeactivatedStaff({
    staff,
    canManage,
}: {
    staff: StaffMember[];
    canManage: boolean;
}) {
    if (staff.length === 0) {
        return <EmptyState icon={UserMinus} title="No deactivated staff" />;
    }

    return (
        <div className="overflow-hidden rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Deactivated</TableHead>
                        {canManage && (
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {staff.map((member) => (
                        <TableRow key={member.id}>
                            <TableCell className="font-medium">
                                {member.name}
                            </TableCell>
                            <TableCell>{member.email}</TableCell>
                            <TableCell>{member.role ?? '—'}</TableCell>
                            <TableCell>
                                <DateText date={member.deactivatedAt} />
                            </TableCell>
                            {canManage && (
                                <TableCell>
                                    <div className="flex justify-end">
                                        <Form
                                            {...reactivate.form({
                                                user: member.id,
                                            })}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                    focusableWhenDisabled
                                                >
                                                    {processing && (
                                                        <Spinner data-icon="inline-start" />
                                                    )}
                                                    Reactivate
                                                </Button>
                                            )}
                                        </Form>
                                    </div>
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function RevokeInvitation({ invitation }: { invitation: Invitation }) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="ghost" size="sm" />}>
                Revoke
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Revoke the invitation to {invitation.email}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The link stops working. You can invite this email again
                        later.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...destroy.form({ staffInvitation: invitation.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
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
                                Revoke invitation
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function Invitations({
    invitations,
    canManage,
}: {
    invitations: Invitation[];
    canManage: boolean;
}) {
    if (invitations.length === 0) {
        return <EmptyState icon={Mail} title="No open invitations" />;
    }

    return (
        <div className="overflow-hidden rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Invited by</TableHead>
                        <TableHead>Sent</TableHead>
                        <TableHead>Expires</TableHead>
                        {canManage && (
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {invitations.map((invitation) => (
                        <TableRow key={invitation.id}>
                            <TableCell className="font-medium">
                                {invitation.email}
                            </TableCell>
                            <TableCell>{invitation.role}</TableCell>
                            <TableCell>{invitation.invitedBy ?? '—'}</TableCell>
                            <TableCell>
                                <DateText date={invitation.lastSentAt} />
                            </TableCell>
                            <TableCell>
                                {invitation.expired ? (
                                    <Badge variant="outline">Expired</Badge>
                                ) : (
                                    <DateText date={invitation.expiresAt} />
                                )}
                            </TableCell>
                            {canManage && (
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        <Form
                                            {...resend.form({
                                                staffInvitation: invitation.id,
                                            })}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                    focusableWhenDisabled
                                                >
                                                    {processing && (
                                                        <Spinner data-icon="inline-start" />
                                                    )}
                                                    Resend
                                                </Button>
                                            )}
                                        </Form>
                                        <RevokeInvitation
                                            invitation={invitation}
                                        />
                                    </div>
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

export default function StaffIndex({
    tab,
    counts,
    canManage,
    roles,
    staff = [],
    invitations = [],
}: {
    tab: Tab;
    counts: { active: number; invited: number; deactivated: number };
    canManage: boolean;
    roles: RoleOption[];
    staff?: StaffMember[];
    invitations?: Invitation[];
}) {
    return (
        <>
            <Head title="Staff" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Staff"
                    description="People who can sign in to this property."
                    actions={canManage && <InviteStaffDialog roles={roles} />}
                />

                <TabLinks
                    activeTab={tab}
                    className="gap-4"
                    tabs={[
                        {
                            value: 'active',
                            label: `Active (${counts.active})`,
                            icon: Users,
                            href: index(),
                        },
                        {
                            value: 'invited',
                            label: `Invited (${counts.invited})`,
                            icon: Mail,
                            href: invited(),
                        },
                        {
                            value: 'deactivated',
                            label: `Deactivated (${counts.deactivated})`,
                            icon: UserMinus,
                            href: deactivated(),
                        },
                    ]}
                >
                    {tab === 'active' && (
                        <ActiveStaff staff={staff} canManage={canManage} />
                    )}
                    {tab === 'deactivated' && (
                        <DeactivatedStaff staff={staff} canManage={canManage} />
                    )}
                    {tab === 'invited' && (
                        <Invitations
                            invitations={invitations}
                            canManage={canManage}
                        />
                    )}
                </TabLinks>
            </div>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'Staff', href: index() }],
};
