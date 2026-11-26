import { router, useForm } from '@inertiajs/react';
import {
    KeyRound,
    Mail,
    Monitor,
    Plus,
    Search,
    ShieldAlert,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { PosVoidReasonForm } from '@/components/pms/pos/pos-void-reason-form';
import type { VoidReasonRow } from '@/components/pms/pos/pos-void-reason-form';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Kbd } from '@/components/ui/kbd';
import { cn } from '@/lib/utils';
import {
    revoke as revokeDevice,
    store as storeDevice,
} from '@/routes/settings/pos/devices';
import {
    invite as invitePin,
    revoke as revokePin,
} from '@/routes/settings/pos/staff-pins';
import { unlessRefused } from '@/components/pms/pos/pos-unless-refused';

type Props = {
    staff: { id: number; name: string; hasPin: boolean }[];
    devices: {
        id: number;
        name: string;
        outlet: string;
        revoked: boolean;
        lastSeenAt: string | null;
    }[];
    outlets: EnumOption[];
    voidReasons: VoidReasonRow[];
};

type StaffTab = 'pins' | 'terminals' | 'reasons';

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

function formatLastSeen(iso: string | null): string {
    if (iso === null) {
        return 'Never used';
    }

    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function PosStaffSettings({
    staff,
    devices,
    outlets,
    voidReasons,
}: Props) {
    const [section, setSection] = useState<StaffTab>('pins');
    const [staffSearch, setStaffSearch] = useState('');
    const [pinFilter, setPinFilter] = useState<'all' | 'set' | 'unset'>('all');
    const [device, setDevice] = useState(false);
    const [reason, setReason] = useState<{ row: VoidReasonRow | null } | null>(
        null,
    );
    const [confirmRevoke, setConfirmRevoke] = useState<{
        title: string;
        description: string;
        url: string;
    } | null>(null);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const deviceForm = useForm({ name: '', pos_outlet_id: '' });

    useEffect(() => {
        const handleSlash = (event: KeyboardEvent) => {
            if (
                event.key !== '/' ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey
            ) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.tagName === 'SELECT' ||
                    target.isContentEditable)
            ) {
                return;
            }

            if (searchInputRef.current) {
                event.preventDefault();
                searchInputRef.current.focus();
            }
        };

        window.addEventListener('keydown', handleSlash);

        return () => window.removeEventListener('keydown', handleSlash);
    }, []);

    const pinsSetCount = useMemo(
        () => staff.filter((person) => person.hasPin).length,
        [staff],
    );
    const activeDevicesCount = useMemo(
        () => devices.filter((row) => !row.revoked).length,
        [devices],
    );

    const filteredStaff = useMemo(() => {
        const needle = staffSearch.trim().toLocaleLowerCase();

        return staff.filter((person) => {
            if (pinFilter === 'set' && !person.hasPin) {
                return false;
            }

            if (pinFilter === 'unset' && person.hasPin) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            return person.name.toLocaleLowerCase().includes(needle);
        });
    }, [staff, pinFilter, staffSearch]);

    const navTabs: {
        value: StaffTab;
        label: string;
        icon: LucideIcon;
        badge: string;
        attention?: boolean;
    }[] = [
        {
            value: 'pins',
            label: 'Staff PINs',
            icon: KeyRound,
            badge: `${pinsSetCount}/${staff.length}`,
            attention: staff.length > 0 && pinsSetCount === 0,
        },
        {
            value: 'terminals',
            label: 'Terminals',
            icon: Monitor,
            badge: String(activeDevicesCount),
            attention: activeDevicesCount === 0,
        },
        {
            value: 'reasons',
            label: 'Void reasons',
            icon: ShieldAlert,
            badge: String(voidReasons.length),
        },
    ];

    const headerActions =
        section === 'reasons' ? (
            <Button
                type="button"
                className="max-sm:min-h-11"
                onClick={() => setReason({ row: null })}
            >
                <Plus data-icon="inline-start" />
                Add void reason
            </Button>
        ) : (
            <Button
                type="button"
                variant={section === 'terminals' ? 'default' : 'outline'}
                className="max-sm:min-h-11"
                onClick={() => setDevice(true)}
            >
                {section === 'terminals' ? (
                    <Plus data-icon="inline-start" />
                ) : (
                    <Monitor data-icon="inline-start" />
                )}
                Register this browser
            </Button>
        );

    return (
        <PosSettingsShell activeTab="staff" actions={headerActions}>
            <nav
                aria-label="Staff and terminal sections"
                className="flex items-center justify-between gap-3 border-b"
            >
                <div
                    role="tablist"
                    aria-label="Staff and terminal sections"
                    className="-mb-px flex max-w-full items-center gap-1 overflow-x-auto"
                >
                    {navTabs.map((item) => {
                        const Icon = item.icon;
                        const active = section === item.value;

                        return (
                            <button
                                key={item.value}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                onClick={() => setSection(item.value)}
                                className={cn(
                                    'inline-flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none max-sm:min-h-11',
                                    active
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                )}
                            >
                                <Icon
                                    aria-hidden="true"
                                    className="size-4 shrink-0"
                                />
                                <span>{item.label}</span>
                                <span
                                    className={cn(
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs tabular-nums',
                                        item.attention
                                            ? 'bg-warning/15 font-medium text-warning-text'
                                            : active
                                              ? 'bg-muted font-medium text-foreground'
                                              : 'bg-muted/70 text-muted-foreground',
                                    )}
                                >
                                    {item.badge}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </nav>

            {section === 'pins' && (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-3 rounded-xl border bg-card px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <InputGroup className="w-full sm:max-w-xs">
                            <InputGroupAddon>
                                <Search aria-hidden />
                            </InputGroupAddon>
                            <InputGroupInput
                                ref={searchInputRef}
                                type="search"
                                aria-label="Search staff"
                                placeholder="Search staff name"
                                value={staffSearch}
                                onChange={(event) =>
                                    setStaffSearch(event.target.value)
                                }
                            />
                            {staffSearch !== '' ? (
                                <InputGroupAddon align="inline-end">
                                    <InputGroupButton
                                        size="icon-xs"
                                        aria-label="Clear staff search"
                                        onClick={() => setStaffSearch('')}
                                    >
                                        <X />
                                    </InputGroupButton>
                                </InputGroupAddon>
                            ) : (
                                <InputGroupAddon
                                    align="inline-end"
                                    className="hidden sm:flex"
                                >
                                    <Kbd>/</Kbd>
                                </InputGroupAddon>
                            )}
                        </InputGroup>

                        <div
                            role="group"
                            aria-label="Filter staff by PIN status"
                            className="flex flex-wrap items-center gap-1"
                        >
                            {(
                                [
                                    {
                                        value: 'all',
                                        label: 'All',
                                        count: staff.length,
                                    },
                                    {
                                        value: 'set',
                                        label: 'PIN set',
                                        count: pinsSetCount,
                                    },
                                    {
                                        value: 'unset',
                                        label: 'Not set',
                                        count: staff.length - pinsSetCount,
                                    },
                                ] as const
                            ).map((item) => (
                                <Button
                                    key={item.value}
                                    type="button"
                                    size="xs"
                                    variant={
                                        pinFilter === item.value
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    aria-pressed={pinFilter === item.value}
                                    className="gap-1.5 tabular-nums"
                                    onClick={() => setPinFilter(item.value)}
                                >
                                    <span>{item.label}</span>
                                    <span className="text-muted-foreground">
                                        {item.count}
                                    </span>
                                </Button>
                            ))}
                        </div>
                    </div>

                    <p className="flex items-center gap-2 px-1 text-xs text-muted-foreground">
                        <KeyRound
                            aria-hidden="true"
                            className="size-3.5 shrink-0"
                        />
                        <span>
                            Staff create their own PIN under Account, POS PIN.
                            Send an email invite to prompt anyone without one,
                            or revoke a PIN immediately if compromised.
                        </span>
                    </p>

                    {filteredStaff.length === 0 ? (
                        <div className="rounded-xl border bg-card p-8">
                            <EmptyState
                                icon={Search}
                                title="No matching staff"
                                description="No staff member matches your current search or filter."
                                action={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setStaffSearch('');
                                            setPinFilter('all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            {filteredStaff.map((person) => (
                                <div
                                    key={person.id}
                                    className="flex flex-col justify-between gap-4 rounded-xl border bg-card p-4 transition-colors hover:bg-muted/20 motion-reduce:transition-none sm:flex-row sm:items-center"
                                >
                                    <div className="flex min-w-0 items-center gap-3.5">
                                        <span
                                            className={cn(
                                                'flex size-11 shrink-0 items-center justify-center rounded-xl border font-heading text-xs font-bold',
                                                person.hasPin
                                                    ? 'border-success/30 bg-success/10 text-success'
                                                    : 'border-border bg-muted/60 text-foreground',
                                            )}
                                        >
                                            {initials(person.name)}
                                        </span>
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="truncate font-medium text-foreground">
                                                    {person.name}
                                                </span>
                                                <StatusBadge
                                                    status={{
                                                        value: person.hasPin
                                                            ? 'set'
                                                            : 'unset',
                                                        label: person.hasPin
                                                            ? 'PIN set'
                                                            : 'Not set',
                                                        tone: person.hasPin
                                                            ? 'success'
                                                            : 'neutral',
                                                    }}
                                                />
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                {person.hasPin
                                                    ? 'Ready for Terminal Mode sign-in.'
                                                    : 'Invite by email to set a PIN.'}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="flex shrink-0 justify-end">
                                        {!person.hasPin ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="max-sm:min-h-11"
                                                aria-label={`Invite ${person.name} to set PIN`}
                                                onClick={() =>
                                                    router.post(
                                                        invitePin.url({
                                                            user: person.id,
                                                        }),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Mail data-icon="inline-start" />
                                                Send invite
                                            </Button>
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="max-sm:min-h-11"
                                                aria-label={`Revoke ${person.name}'s PIN`}
                                                onClick={() =>
                                                    setConfirmRevoke({
                                                        title: `Revoke ${person.name}'s PIN?`,
                                                        description:
                                                            'They will be signed out of Terminal Mode and must set a new PIN under Account.',
                                                        url: revokePin.url({
                                                            user: person.id,
                                                        }),
                                                    })
                                                }
                                            >
                                                Revoke PIN
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {section === 'terminals' && (
                <div className="flex flex-col gap-4">
                    <p className="text-xs text-muted-foreground">
                        Register each shared counter browser once so staff can
                        lock the till and switch cashiers with their personal
                        PIN.
                    </p>

                    {devices.length === 0 ? (
                        <div className="rounded-xl border bg-card p-8">
                            <EmptyState
                                icon={Monitor}
                                title="No registered terminals"
                                description="Use Register this browser above on a counter device before entering Terminal Mode."
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {devices.map((row) => (
                                <div
                                    key={row.id}
                                    className="flex flex-col justify-between gap-4 rounded-xl border bg-card p-4"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span
                                                className={cn(
                                                    'flex size-10 shrink-0 items-center justify-center rounded-xl border',
                                                    row.revoked
                                                        ? 'border-border bg-muted/40 text-muted-foreground'
                                                        : 'border-success/30 bg-success/10 text-success',
                                                )}
                                            >
                                                <Monitor
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            </span>
                                            <div className="flex min-w-0 flex-col gap-0.5">
                                                <span className="truncate font-heading text-sm font-semibold text-foreground">
                                                    {row.name}
                                                </span>
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {row.outlet}
                                                </span>
                                            </div>
                                        </div>
                                        <StatusBadge
                                            status={{
                                                value: row.revoked
                                                    ? 'revoked'
                                                    : 'active',
                                                label: row.revoked
                                                    ? 'Revoked'
                                                    : 'Active',
                                                tone: row.revoked
                                                    ? 'neutral'
                                                    : 'success',
                                            }}
                                        />
                                    </div>

                                    <div className="flex items-center justify-between gap-2 border-t pt-3">
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            Last seen:{' '}
                                            {formatLastSeen(row.lastSeenAt)}
                                        </span>
                                        {!row.revoked && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="xs"
                                                className="max-sm:min-h-11"
                                                aria-label={`Revoke terminal ${row.name}`}
                                                onClick={() =>
                                                    setConfirmRevoke({
                                                        title: `Revoke terminal ${row.name}?`,
                                                        description:
                                                            'Staff will no longer be able to sign in with a PIN on this browser.',
                                                        url: revokeDevice.url({
                                                            device: row.id,
                                                        }),
                                                    })
                                                }
                                            >
                                                Revoke
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {section === 'reasons' && (
                <div className="flex flex-col gap-4">
                    <p className="text-xs text-muted-foreground">
                        Cashiers select one of these reasons whenever they void
                        a fired dish or cancel an open check.
                    </p>

                    {voidReasons.length === 0 ? (
                        <div className="rounded-xl border bg-card p-8">
                            <EmptyState
                                icon={ShieldAlert}
                                title="No void reasons yet"
                                description="Use Add void reason above to add options such as Kitchen error, Guest changed mind, or Out of stock."
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            {voidReasons.map((row) => (
                                <div
                                    key={row.id}
                                    className="flex flex-col justify-between gap-4 rounded-xl border bg-card p-4 sm:flex-row sm:items-center"
                                >
                                    <div className="flex min-w-0 items-center gap-3.5">
                                        <span
                                            className={cn(
                                                'flex size-10 shrink-0 items-center justify-center rounded-xl border',
                                                !row.active
                                                    ? 'border-border bg-muted/40 text-muted-foreground'
                                                    : row.requires_manager_pin
                                                      ? 'border-warning/40 bg-warning/10 text-warning-text'
                                                      : 'border-border bg-muted/60 text-foreground',
                                            )}
                                        >
                                            <ShieldAlert
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                        </span>
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="truncate font-medium text-foreground">
                                                    {row.label}
                                                </span>
                                                <StatusBadge
                                                    status={{
                                                        value: row.active
                                                            ? 'active'
                                                            : 'inactive',
                                                        label: row.active
                                                            ? 'Active'
                                                            : 'Inactive',
                                                        tone: row.active
                                                            ? 'success'
                                                            : 'neutral',
                                                    }}
                                                />
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <Badge
                                                    variant="secondary"
                                                    className={
                                                        row.requires_manager_pin
                                                            ? 'bg-warning/10 text-warning-text'
                                                            : 'bg-muted text-muted-foreground'
                                                    }
                                                >
                                                    {row.requires_manager_pin
                                                        ? 'Manager PIN required'
                                                        : 'Cashier allowed'}
                                                </Badge>
                                                <span>
                                                    {row.default_return_stock
                                                        ? 'Returns stock'
                                                        : 'Writes off stock'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex shrink-0 justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="max-sm:min-h-11"
                                            aria-label={`Edit ${row.label}`}
                                            onClick={() => setReason({ row })}
                                        >
                                            Edit
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            <FormOverlay
                variant="dialog"
                open={device}
                onOpenChange={setDevice}
                title="Register this browser"
                description="Pair this browser with an outlet so staff can sign in and switch cashiers with a PIN in Terminal Mode."
                errors={deviceForm.errors}
                processing={deviceForm.processing}
                submitLabel="Register terminal"
                onSubmit={() =>
                    deviceForm.post(storeDevice.url(), {
                        preserveScroll: true,
                        onSuccess: unlessRefused(() => {
                            setDevice(false);
                            deviceForm.reset();
                        }),
                    })
                }
            >
                <Field data-invalid={!!deviceForm.errors.name}>
                    <FieldLabel htmlFor="device-name">Terminal name</FieldLabel>
                    <Input
                        id="device-name"
                        maxLength={80}
                        placeholder="e.g. Front Counter iPad"
                        value={deviceForm.data.name}
                        onChange={(event) =>
                            deviceForm.setData('name', event.target.value)
                        }
                        autoFocus
                    />
                    <FieldError>{deviceForm.errors.name}</FieldError>
                </Field>
                <Field data-invalid={!!deviceForm.errors.pos_outlet_id}>
                    <FieldLabel htmlFor="device-outlet">Outlet</FieldLabel>
                    <EnumSelect
                        id="device-outlet"
                        name="pos_outlet_id"
                        options={outlets}
                        value={deviceForm.data.pos_outlet_id}
                        onValueChange={(value) =>
                            deviceForm.setData('pos_outlet_id', value)
                        }
                        placeholder="Pick an outlet"
                    />
                    <FieldError>{deviceForm.errors.pos_outlet_id}</FieldError>
                </Field>
            </FormOverlay>

            {reason && (
                <PosVoidReasonForm
                    key={reason.row?.id ?? 'new'}
                    row={reason.row}
                    onClose={() => setReason(null)}
                />
            )}

            <AlertDialog
                open={confirmRevoke !== null}
                onOpenChange={(open) => !open && setConfirmRevoke(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {confirmRevoke?.title}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirmRevoke?.description}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={() => {
                                if (confirmRevoke !== null) {
                                    router.post(
                                        confirmRevoke.url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () =>
                                                setConfirmRevoke(null),
                                        },
                                    );
                                }
                            }}
                        >
                            Revoke
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </PosSettingsShell>
    );
}

PosStaffSettings.layout = {
    breadcrumbs: posSettingsBreadcrumbs('staff'),
};
