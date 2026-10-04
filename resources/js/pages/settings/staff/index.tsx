import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ChevronDown,
    Copy,
    Ellipsis,
    KeyRound,
    ListPlus,
    Mail,
    Send,
    UserCheck,
    UserCog,
    UserMinus,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { AddStaffDialog } from '@/components/pms/staff/add-staff-dialog';
import { ExportTemporaryPasswordsMenu } from '@/components/pms/staff/export-temporary-passwords-menu';
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
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
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
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
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
import { create as bulkCreate } from '@/routes/settings/staff/bulk';
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
                                        placeholder="ali@hotel.com"
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
                                        placeholder="Choose a role"
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
                    <DropdownMenuItem render={<Link href={bulkCreate()} />}>
                        <ListPlus />
                        Bulk add
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
        return (
            <StatusBadge
                status={{ value: 'active', label: 'Active', tone: 'success' }}
            />
        );
    }

    const daysLeft = temporaryPasswordDaysLeft(
        member.temporaryPasswordExpiresAt,
    );

    return (
        <StatusBadge
            status={
                daysLeft === null
                    ? {
                          value: 'temp-expired',
                          label: 'Temp password expired',
                          tone: 'danger',
                      }
                    : {
                          value: 'temp',
                          label: `Temp password · ${daysLeft}d`,
                          tone: 'warning',
                      }
            }
        />
    );
}

function DeactivateStaff({
    member,
    open,
    onOpenChange: setOpen,
}: {
    member: StaffMember;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
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
    open,
    onOpenChange: setOpen,
}: {
    member: StaffMember;
    roles: RoleOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={setOpen}>
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
                                    placeholder="Choose a role"
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

function RowActionsTrigger({ name }: { name: string }) {
    return (
        <DropdownMenuTrigger
            render={<Button variant="ghost" size="icon-sm" />}
            aria-label={`Actions for ${name}`}
        >
            <Ellipsis />
        </DropdownMenuTrigger>
    );
}

function ActiveStaffActions({
    member,
    roles,
    onShowMessage,
}: {
    member: StaffMember;
    roles: RoleOption[];
    onShowMessage: (userIds: number[]) => void;
}) {
    const [dialog, setDialog] = useState<
        'role' | 'reset' | 'deactivate' | null
    >(null);
    const close = (open: boolean) => !open && setDialog(null);
    const canCopy =
        member.temporaryPasswordExpiresAt !== null &&
        temporaryPasswordDaysLeft(member.temporaryPasswordExpiresAt) !== null;

    return (
        <>
            <DropdownMenu>
                <RowActionsTrigger name={member.name} />
                <DropdownMenuContent align="end" className="w-48">
                    {canCopy && (
                        <DropdownMenuItem
                            onClick={() => onShowMessage([member.id])}
                        >
                            <Copy />
                            Copy message
                        </DropdownMenuItem>
                    )}
                    {!member.isOwner && (
                        <DropdownMenuItem onClick={() => setDialog('reset')}>
                            <KeyRound />
                            Reset password
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onClick={() => setDialog('role')}>
                        <UserCog />
                        Change role
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onClick={() => setDialog('deactivate')}
                    >
                        <UserMinus />
                        Deactivate
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <ChangeRoleDialog
                member={member}
                roles={roles}
                open={dialog === 'role'}
                onOpenChange={close}
            />
            <ResetTemporaryPassword
                userIds={[member.id]}
                target={member.name}
                open={dialog === 'reset'}
                onOpenChange={close}
                onReset={onShowMessage}
            />
            <DeactivateStaff
                member={member}
                open={dialog === 'deactivate'}
                onOpenChange={close}
            />
        </>
    );
}

function DeactivatedStaffActions({ member }: { member: StaffMember }) {
    return (
        <DropdownMenu>
            <RowActionsTrigger name={member.name} />
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuItem
                    onClick={() =>
                        router.post(
                            reactivate.url({ user: member.id }),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <UserCheck />
                    Reactivate
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function InvitationActions({ invitation }: { invitation: Invitation }) {
    const [revoking, setRevoking] = useState(false);

    return (
        <>
            <DropdownMenu>
                <RowActionsTrigger name={invitation.email} />
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem
                        onClick={() =>
                            router.post(
                                resend.url({ staffInvitation: invitation.id }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Send />
                        Resend
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onClick={() => setRevoking(true)}
                    >
                        <X />
                        Revoke
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <RevokeInvitation
                invitation={invitation}
                open={revoking}
                onOpenChange={setRevoking}
            />
        </>
    );
}

function SelectionBar({
    selected,
    exportFormats,
    onShowMessage,
    onClear,
}: {
    selected: number[];
    exportFormats: EnumOption[];
    onShowMessage: (userIds: number[]) => void;
    onClear: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/50 px-3 py-2">
            <span className="text-sm font-medium">
                Selected ({selected.length})
            </span>
            <Button
                variant="outline"
                size="sm"
                onClick={() => onShowMessage(selected)}
            >
                Copy message
            </Button>
            <ExportTemporaryPasswordsMenu
                userIds={selected}
                formats={exportFormats}
                size="sm"
            />
            <ResetTemporaryPassword
                userIds={selected}
                target={`${selected.length} staff`}
                variant="outline"
                onReset={(userIds) => {
                    onShowMessage(userIds);
                    onClear();
                }}
            />
            <Button
                variant="ghost"
                size="sm"
                className="ms-auto"
                onClick={onClear}
            >
                Clear
            </Button>
        </div>
    );
}

function ActiveStaff({
    staff,
    roles,
    canManage,
    exportFormats,
    onShowMessage,
}: {
    staff: StaffMember[];
    roles: RoleOption[];
    canManage: boolean;
    exportFormats: EnumOption[];
    onShowMessage: (userIds: number[]) => void;
}) {
    const [chosen, setSelected] = useState<number[]>([]);
    // Staff that left the list (deactivated, for example) drop out of the selection.
    const selected = chosen.filter((id) =>
        staff.some((member) => member.id === id),
    );
    const allSelected = staff.length > 0 && selected.length === staff.length;

    const toggle = (userId: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, userId]
                : current.filter((id) => id !== userId),
        );

    return (
        <div className="flex flex-col gap-3">
            {canManage && selected.length > 0 && (
                <SelectionBar
                    selected={selected}
                    exportFormats={exportFormats}
                    onShowMessage={onShowMessage}
                    onClear={() => setSelected([])}
                />
            )}
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {canManage && (
                                <TableHead className="w-10">
                                    <Checkbox
                                        aria-label="Select all staff"
                                        checked={allSelected}
                                        indeterminate={
                                            selected.length > 0 && !allSelected
                                        }
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked
                                                    ? staff.map(
                                                          (member) => member.id,
                                                      )
                                                    : [],
                                            )
                                        }
                                    />
                                </TableHead>
                            )}
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
                            <TableRow
                                key={member.id}
                                data-state={
                                    selected.includes(member.id)
                                        ? 'selected'
                                        : undefined
                                }
                            >
                                {canManage && (
                                    <TableCell>
                                        <Checkbox
                                            aria-label={`Select ${member.name}`}
                                            checked={selected.includes(
                                                member.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(member.id, checked)
                                            }
                                        />
                                    </TableCell>
                                )}
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
                                            <div className="flex justify-end">
                                                <ActiveStaffActions
                                                    member={member}
                                                    roles={roles}
                                                    onShowMessage={
                                                        onShowMessage
                                                    }
                                                />
                                            </div>
                                        )}
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
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
                    {staff.length === 0 && (
                        <TableRow>
                            <TableCell colSpan={canManage ? 5 : 4}>
                                <EmptyState
                                    icon={UserMinus}
                                    title="No deactivated staff"
                                />
                            </TableCell>
                        </TableRow>
                    )}
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
                                        <DeactivatedStaffActions
                                            member={member}
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

function RevokeInvitation({
    invitation,
    open,
    onOpenChange: setOpen,
}: {
    invitation: Invitation;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
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
                    {invitations.length === 0 && (
                        <TableRow>
                            <TableCell colSpan={canManage ? 6 : 5}>
                                <EmptyState
                                    icon={Mail}
                                    title="No open invitations"
                                />
                            </TableCell>
                        </TableRow>
                    )}
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
                                    <StatusBadge
                                        status={{
                                            value: 'expired',
                                            label: 'Expired',
                                            tone: 'warning',
                                        }}
                                    />
                                ) : (
                                    <DateText date={invitation.expiresAt} />
                                )}
                            </TableCell>
                            {canManage && (
                                <TableCell>
                                    <div className="flex justify-end">
                                        <InvitationActions
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
    exportFormats,
    staff = [],
    invitations = [],
}: {
    tab: Tab;
    counts: { active: number; invited: number; deactivated: number };
    canManage: boolean;
    roles: RoleOption[];
    exportFormats: EnumOption[];
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
                    panelWidth="full"
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
                            exportFormats={exportFormats}
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
