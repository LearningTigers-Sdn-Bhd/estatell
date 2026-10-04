import { Form, Head } from '@inertiajs/react';
import { ChevronDown, KeyRound, Mail, UserMinus, Users } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { AddStaffDialog } from '@/components/pms/staff/add-staff-dialog';
import { ResetTemporaryPassword } from '@/components/pms/staff/reset-temporary-password';
import { TemporaryPasswordMessageDialog } from '@/components/pms/staff/temporary-password-message-dialog';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { update as roleUpdate } from '@/routes/settings/staff/role';

type StaffMember = App.Data.Staff.StaffMemberData;
type Invitation = App.Data.Staff.StaffInvitationData;
type RoleOption = App.Data.Staff.RoleOptionData;
type Tab = 'active' | 'invited' | 'deactivated';

function InviteStaffDialog({
    roles,
    open,
    onOpenChange: setOpen,
}: {
    roles: RoleOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={setOpen}>
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

function AddStaffMenu({
    roles,
    onShowMessage,
}: {
    roles: RoleOption[];
    onShowMessage: (userIds: number[]) => void;
}) {
    const [inviting, setInviting] = useState(false);
    const [adding, setAdding] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger render={<Button />}>
                    Add staff
                    <ChevronDown data-icon="inline-end" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuItem onClick={() => setInviting(true)}>
                        <Mail />
                        Invite by email
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => setAdding(true)}>
                        <KeyRound />
                        Add with temp password
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <InviteStaffDialog
                roles={roles}
                open={inviting}
                onOpenChange={setInviting}
            />
            <AddStaffDialog
                roles={roles}
                open={adding}
                onOpenChange={setAdding}
                onAdded={(userId) => onShowMessage([userId])}
            />
        </>
    );
}

/**
 * Days left on a temp password, counted in the browser for display only. Null when expired.
 */
function temporaryPasswordDaysLeft(expiresAt: string): number | null {
    const msLeft = new Date(expiresAt).getTime() - Date.now();

    return msLeft > 0 ? Math.ceil(msLeft / 86_400_000) : null;
}

function StaffStatus({ member }: { member: StaffMember }) {
    if (member.temporaryPasswordExpiresAt === null) {
        return <span className="text-muted-foreground">Active</span>;
    }

    const daysLeft = temporaryPasswordDaysLeft(
        member.temporaryPasswordExpiresAt,
    );

    return daysLeft === null ? (
        <Badge variant="destructive">Temp password expired</Badge>
    ) : (
        <Badge variant="outline">Temp password · {daysLeft}d</Badge>
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

function ChangeRoleDialog({
    member,
    roles,
}: {
    member: StaffMember;
    roles: RoleOption[];
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button variant="ghost" size="sm" />}>
                Change role
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Change the role of {member.name}</DialogTitle>
                    <DialogDescription>
                        The new role replaces the old one straight away.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...roleUpdate.form({ user: member.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field>
                                <FieldLabel htmlFor={`role-${member.id}`}>
                                    Role
                                </FieldLabel>
                                <EnumSelect
                                    id={`role-${member.id}`}
                                    name="role_id"
                                    options={roles}
                                    defaultValue={
                                        member.roleId === null
                                            ? undefined
                                            : String(member.roleId)
                                    }
                                    aria-invalid={!!errors.role_id}
                                />
                                <FieldError>{errors.role_id}</FieldError>
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
                                    Save role
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ActiveStaff({
    staff,
    roles,
    canManage,
    onShowMessage,
}: {
    staff: StaffMember[];
    roles: RoleOption[];
    canManage: boolean;
    onShowMessage: (userIds: number[]) => void;
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
                        <TableHead>Status</TableHead>
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
                            <TableCell>
                                <StaffStatus member={member} />
                            </TableCell>
                            {canManage && (
                                <TableCell>
                                    {!member.isYou && (
                                        <div className="flex justify-end gap-1">
                                            {member.temporaryPasswordExpiresAt !==
                                                null &&
                                                temporaryPasswordDaysLeft(
                                                    member.temporaryPasswordExpiresAt,
                                                ) !== null && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            onShowMessage([
                                                                member.id,
                                                            ])
                                                        }
                                                    >
                                                        Copy message
                                                    </Button>
                                                )}
                                            {!member.isOwner && (
                                                <ResetTemporaryPassword
                                                    member={member}
                                                    onReset={(userId) =>
                                                        onShowMessage([userId])
                                                    }
                                                />
                                            )}
                                            <ChangeRoleDialog
                                                member={member}
                                                roles={roles}
                                            />
                                            <DeactivateStaff member={member} />
                                        </div>
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
    const [messageUserIds, setMessageUserIds] = useState<number[] | null>(null);

    return (
        <>
            <Head title="Staff" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Staff"
                    description="People who can sign in to this property."
                    actions={
                        canManage && (
                            <AddStaffMenu
                                roles={roles}
                                onShowMessage={setMessageUserIds}
                            />
                        )
                    }
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
                        <ActiveStaff
                            staff={staff}
                            roles={roles}
                            canManage={canManage}
                            onShowMessage={setMessageUserIds}
                        />
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

            {canManage && (
                <TemporaryPasswordMessageDialog
                    userIds={messageUserIds ?? []}
                    open={messageUserIds !== null}
                    onOpenChange={(open) => !open && setMessageUserIds(null)}
                />
            )}
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'Staff', href: index() }],
};
