import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ChefHat,
    Clock,
    Lock,
    LogOut,
    Receipt,
    Store,
    UserRound,
    WifiOff,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import { PosNavbar } from '@/components/pms/pos/pos-navbar';
import { PosPinPad } from '@/components/pms/pos/pos-pin-pad';
import type { PosCurrency, PosStaff } from '@/components/pms/pos/pos-types';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { clock as posClock, index as posIndex, terminal } from '@/routes/pos';
import { index as kdsIndex } from '@/routes/pos/kds';
import { show as offlineShow } from '@/routes/pos/offline';
import { open } from '@/routes/pos/shifts';
import { enter, exit } from '@/routes/pos/terminal-mode';
import { index as posOutletsSettings } from '@/routes/settings/pos-outlets';
import { index as posRegistersSettings } from '@/routes/settings/pos/outlets';

type Register = {
    id: number;
    name: string;
    shiftNumber: string | null;
    shiftStatus: App.Data.Shared.BadgeData;
    openedBy: string | null;
    openOrderCount: number;
};

type Outlet = {
    id: number;
    name: string;
    operationMode: string;
    requireClockIn: boolean;
    registers: Register[];
};

type Props = {
    currency: PosCurrency;
    locked: boolean;
    hasDevice: boolean;
    actor: PosStaff | null;
    clock: { clockedInOutletId: number | null } | null;
    canShift: boolean;
    canKds: boolean;
    canManage: boolean;
    staff: PosStaff[];
    outlets: Outlet[];
};

const CLOCKED_IN_BADGE: App.Data.Shared.BadgeData = {
    value: 'clocked_in',
    label: 'Clocked in',
    tone: 'success',
};

export default function PosIndex({
    currency,
    locked,
    hasDevice,
    actor,
    clock,
    canShift,
    canKds,
    canManage,
    staff,
    outlets,
}: Props) {
    const [opening, setOpening] = useState<{
        outlet: Outlet;
        register: Register;
    } | null>(null);
    const [leaving, setLeaving] = useState(false);
    const [switching, setSwitching] = useState(false);
    const [switchKey, setSwitchKey] = useState(0);
    const [busyAction, setBusyAction] = useState<string | null>(null);

    const openForm = useForm({ register_id: '', opening_cash: '' });
    const exitForm = useForm({ password: '' });

    const clockIn = (outletId: number) =>
        router.post(
            posClock.url(),
            { action: 'in', outlet_id: outletId },
            {
                preserveScroll: true,
                onStart: () => setBusyAction(`clock-in-${outletId}`),
                onFinish: () => setBusyAction(null),
            },
        );

    const clockOut = () =>
        router.post(
            posClock.url(),
            { action: 'out' },
            {
                preserveScroll: true,
                onStart: () => setBusyAction('clock-out'),
                onFinish: () => setBusyAction(null),
            },
        );

    const enterTerminalMode = () =>
        router.post(
            enter.url(),
            {},
            {
                onStart: () => setBusyAction('enter-terminal'),
                onFinish: () => setBusyAction(null),
            },
        );

    const clockedInOutlet =
        clock?.clockedInOutletId === null ||
        clock?.clockedInOutletId === undefined
            ? null
            : (outlets.find((o) => o.id === clock.clockedInOutletId) ?? null);

    const leaveTerminalDialog = (
        <FormOverlay
            variant="dialog"
            open={leaving}
            onOpenChange={(value) => {
                setLeaving(value);
                if (!value) {
                    exitForm.reset();
                    exitForm.clearErrors();
                }
            }}
            title="Leave Terminal Mode"
            description="Enter your own password to unlock the back office on this browser."
            errors={exitForm.errors}
            processing={exitForm.processing}
            submitLabel="Leave Terminal Mode"
            onSubmit={() =>
                exitForm.post(exit.url(), {
                    onFinish: () => exitForm.reset(),
                })
            }
        >
            <Field data-invalid={!!exitForm.errors.password}>
                <FieldLabel htmlFor="exit-password">Password</FieldLabel>
                <Input
                    id="exit-password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    className="min-h-11"
                    aria-invalid={!!exitForm.errors.password}
                    value={exitForm.data.password}
                    onChange={(event) =>
                        exitForm.setData('password', event.target.value)
                    }
                    autoFocus
                />
                <FieldError>{exitForm.errors.password}</FieldError>
            </Field>
        </FormOverlay>
    );

    if (locked && actor === null) {
        return (
            <>
                <Head title="POS sign-in" />
                <PosNavbar
                    right={
                        <>
                            <StatusBadge
                                status={{
                                    value: 'terminal_mode',
                                    label: 'Terminal Mode',
                                    tone: 'info',
                                }}
                                dot={false}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                className="min-h-11 px-3"
                                onClick={() => {
                                    exitForm.clearErrors();
                                    setLeaving(true);
                                }}
                            >
                                <LogOut
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                Leave Terminal Mode
                            </Button>
                        </>
                    }
                />

                <main className="flex flex-1 flex-col justify-center bg-muted/40 px-4 py-8 sm:px-6">
                    <div className="mx-auto flex w-full max-w-xl flex-col gap-6">
                        <PageHeader
                            title="Who is selling?"
                            description="Tap your name, then key your PIN. Not listed? Set your POS PIN under Account."
                        />
                        {!hasDevice && (
                            <Alert variant="destructive">
                                <AlertCircle aria-hidden="true" />
                                <AlertTitle>
                                    This browser is not a registered terminal
                                </AlertTitle>
                                <AlertDescription>
                                    Ask a manager to register this browser in
                                    POS under Staff &amp; terminals. PIN sign-in
                                    is blocked until it is registered.
                                </AlertDescription>
                            </Alert>
                        )}
                        {!hasDevice ? null : staff.length === 0 ? (
                            <EmptyState
                                icon={UserRound}
                                title="No staff have a PIN yet"
                                description="Staff set their own PIN under Account, POS PIN, or a manager can send a PIN invite from Staff & terminals."
                            />
                        ) : (
                            <Card>
                                <CardContent>
                                    <PosPinPad staff={staff} />
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </main>
                {leaveTerminalDialog}
            </>
        );
    }

    const currencyCode = currency?.code ?? null;

    return (
        <>
            <Head title="Point of sale" />

            <PosNavbar
                back={
                    !locked
                        ? { href: dashboard.url(), label: 'Back office' }
                        : null
                }
                identityHref={!locked ? dashboard.url() : undefined}
                center={
                    canKds ? (
                        <nav
                            aria-label="POS displays"
                            className="inline-flex items-center gap-0.5 rounded-lg bg-muted p-1"
                        >
                            <Link
                                href={posIndex.url()}
                                aria-current="page"
                                className="inline-flex min-h-11 items-center gap-1.5 rounded-md bg-card px-3.5 text-sm font-semibold text-foreground shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                            >
                                <Receipt
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Registers
                            </Link>
                            <Link
                                href={kdsIndex.url()}
                                className="inline-flex min-h-11 items-center gap-1.5 rounded-md px-3.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                            >
                                <ChefHat
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Kitchen display
                            </Link>
                        </nav>
                    ) : undefined
                }
                right={
                    locked ? (
                        <StatusBadge
                            status={{
                                value: 'terminal_mode',
                                label: 'Terminal Mode',
                                tone: 'info',
                            }}
                            dot={false}
                        />
                    ) : undefined
                }
                showUser={!locked}
            />

            <main className="flex flex-1 flex-col bg-muted/40">
                <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 py-6 sm:px-6 sm:py-8">
                    <PageHeader
                        title="Point of sale"
                        description={
                            actor
                                ? clockedInOutlet
                                    ? `Signed in as ${actor.name}. Clocked in at ${clockedInOutlet.name}.`
                                    : `Signed in as ${actor.name}.`
                                : undefined
                        }
                        actions={
                            <div className="flex flex-wrap gap-2">
                                {clock?.clockedInOutletId !== null &&
                                    clock?.clockedInOutletId !== undefined && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11"
                                            disabled={busyAction !== null}
                                            focusableWhenDisabled
                                            onClick={clockOut}
                                        >
                                            {busyAction === 'clock-out' ? (
                                                <Spinner data-icon="inline-start" />
                                            ) : (
                                                <Clock
                                                    data-icon="inline-start"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            Clock out
                                        </Button>
                                    )}
                                {locked ? (
                                    <>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11"
                                            onClick={() => {
                                                setSwitchKey((k) => k + 1);
                                                setSwitching(true);
                                            }}
                                        >
                                            <UserRound
                                                data-icon="inline-start"
                                                aria-hidden="true"
                                            />
                                            Switch user
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11"
                                            onClick={() => {
                                                exitForm.clearErrors();
                                                setLeaving(true);
                                            }}
                                        >
                                            <LogOut
                                                data-icon="inline-start"
                                                aria-hidden="true"
                                            />
                                            Leave Terminal Mode
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                        disabled={busyAction !== null}
                                        focusableWhenDisabled
                                        onClick={enterTerminalMode}
                                    >
                                        {busyAction === 'enter-terminal' ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <Lock
                                                data-icon="inline-start"
                                                aria-hidden="true"
                                            />
                                        )}
                                        Enter Terminal Mode
                                    </Button>
                                )}
                            </div>
                        }
                    />

                    {outlets.length === 0 ? (
                        <Card>
                            <CardContent className="py-10">
                                <EmptyState
                                    icon={Store}
                                    title="No outlet is ready to sell"
                                    description="Enable POS on an outlet and add at least one register to start taking orders."
                                    action={
                                        canManage ? (
                                            <Link
                                                href={posOutletsSettings.url()}
                                                className={`${buttonVariants()} min-h-11`}
                                            >
                                                Open outlet settings
                                            </Link>
                                        ) : undefined
                                    }
                                />
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="flex flex-col gap-8">
                            {outlets.map((outlet) => {
                                const clockedHere =
                                    clock?.clockedInOutletId === outlet.id;
                                const openCount = outlet.registers.filter(
                                    (r) => r.shiftNumber !== null,
                                ).length;
                                const totalCount = outlet.registers.length;
                                const summaryParts = [
                                    outlet.operationMode,
                                    totalCount > 0
                                        ? `${openCount} of ${totalCount} ${totalCount === 1 ? 'register' : 'registers'} open`
                                        : 'No registers',
                                ];

                                return (
                                    <section
                                        key={outlet.id}
                                        aria-labelledby={`outlet-${outlet.id}`}
                                        className="flex flex-col gap-4"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-3 border-b pb-3">
                                            <div className="flex min-w-0 flex-col gap-0.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h2
                                                        id={`outlet-${outlet.id}`}
                                                        className="font-heading text-lg font-semibold tracking-tight text-foreground"
                                                    >
                                                        {outlet.name}
                                                    </h2>
                                                    {clockedHere && (
                                                        <StatusBadge
                                                            status={
                                                                CLOCKED_IN_BADGE
                                                            }
                                                        />
                                                    )}
                                                </div>
                                                <p className="text-sm text-muted-foreground tabular-nums">
                                                    {summaryParts.join(' · ')}
                                                </p>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
                                                {!clockedHere &&
                                                    clock?.clockedInOutletId ===
                                                        null && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            className="min-h-11"
                                                            disabled={
                                                                busyAction !==
                                                                null
                                                            }
                                                            focusableWhenDisabled
                                                            onClick={() =>
                                                                clockIn(
                                                                    outlet.id,
                                                                )
                                                            }
                                                        >
                                                            {busyAction ===
                                                            `clock-in-${outlet.id}` ? (
                                                                <Spinner data-icon="inline-start" />
                                                            ) : (
                                                                <Clock
                                                                    data-icon="inline-start"
                                                                    aria-hidden="true"
                                                                />
                                                            )}
                                                            Clock in
                                                        </Button>
                                                    )}
                                                {totalCount > 0 && (
                                                    <Link
                                                        href={offlineShow.url(
                                                            undefined,
                                                            {
                                                                query: {
                                                                    outlet: outlet.id,
                                                                },
                                                            },
                                                        )}
                                                        className={buttonVariants(
                                                            {
                                                                variant:
                                                                    'ghost',
                                                                className:
                                                                    'min-h-11',
                                                            },
                                                        )}
                                                    >
                                                        <WifiOff
                                                            data-icon="inline-start"
                                                            aria-hidden="true"
                                                        />
                                                        Offline walk-in
                                                    </Link>
                                                )}
                                            </div>
                                        </div>

                                        {totalCount === 0 ? (
                                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed bg-muted/30 p-4">
                                                <p className="text-sm text-muted-foreground">
                                                    No register is configured
                                                    for {outlet.name} yet.
                                                </p>
                                                {canManage && (
                                                    <Link
                                                        href={posRegistersSettings.url()}
                                                        className={buttonVariants(
                                                            {
                                                                variant:
                                                                    'outline',
                                                                className:
                                                                    'min-h-11',
                                                            },
                                                        )}
                                                    >
                                                        Add register
                                                    </Link>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                                {outlet.registers.map(
                                                    (register) => {
                                                        const isOpen =
                                                            register.shiftNumber !==
                                                            null;
                                                        const isClosing =
                                                            register.shiftStatus
                                                                .value ===
                                                            'closing_control';

                                                        return (
                                                            <article
                                                                key={
                                                                    register.id
                                                                }
                                                                className={
                                                                    isOpen
                                                                        ? 'flex flex-col justify-between gap-5 rounded-xl border border-primary/45 bg-card p-5 ring-1 ring-primary/15'
                                                                        : 'flex flex-col justify-between gap-5 rounded-xl border bg-muted/30 p-5'
                                                                }
                                                            >
                                                                <div className="flex flex-col gap-2">
                                                                    <div className="flex items-start justify-between gap-2">
                                                                        <h3 className="font-heading text-base font-semibold text-foreground">
                                                                            {
                                                                                register.name
                                                                            }
                                                                        </h3>
                                                                        <StatusBadge
                                                                            status={
                                                                                register.shiftStatus
                                                                            }
                                                                        />
                                                                    </div>

                                                                    {isOpen ? (
                                                                        <dl className="flex flex-col gap-1 text-xs text-muted-foreground tabular-nums">
                                                                            <div className="flex items-center justify-between gap-2">
                                                                                <dt>
                                                                                    Shift
                                                                                </dt>
                                                                                <dd className="font-medium text-foreground">
                                                                                    {
                                                                                        register.shiftNumber
                                                                                    }
                                                                                </dd>
                                                                            </div>
                                                                            {register.openedBy && (
                                                                                <div className="flex items-center justify-between gap-2">
                                                                                    <dt>
                                                                                        Opened
                                                                                        by
                                                                                    </dt>
                                                                                    <dd className="truncate text-foreground">
                                                                                        {
                                                                                            register.openedBy
                                                                                        }
                                                                                    </dd>
                                                                                </div>
                                                                            )}
                                                                            {register.openOrderCount >
                                                                                0 && (
                                                                                <div className="flex items-center justify-between gap-2">
                                                                                    <dt>
                                                                                        Open
                                                                                        checks
                                                                                    </dt>
                                                                                    <dd className="font-medium text-foreground">
                                                                                        {
                                                                                            register.openOrderCount
                                                                                        }
                                                                                    </dd>
                                                                                </div>
                                                                            )}
                                                                        </dl>
                                                                    ) : (
                                                                        <p className="text-xs text-muted-foreground">
                                                                            No
                                                                            active
                                                                            shift.
                                                                            {!canShift &&
                                                                                ' Ask a shift manager to open this register.'}
                                                                        </p>
                                                                    )}
                                                                </div>

                                                                <div className="flex flex-col gap-2">
                                                                    {isOpen ? (
                                                                        <Link
                                                                            className={`${buttonVariants()} min-h-11 font-semibold`}
                                                                            href={terminal.url(
                                                                                {
                                                                                    outlet: outlet.id,
                                                                                },
                                                                                {
                                                                                    query: {
                                                                                        register:
                                                                                            register.id,
                                                                                    },
                                                                                },
                                                                            )}
                                                                        >
                                                                            {isClosing
                                                                                ? 'Resume shift close'
                                                                                : 'Open register'}
                                                                        </Link>
                                                                    ) : canShift ? (
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            className="min-h-11 bg-card"
                                                                            onClick={() => {
                                                                                openForm.clearErrors();
                                                                                openForm.setData(
                                                                                    {
                                                                                        register_id:
                                                                                            String(
                                                                                                register.id,
                                                                                            ),
                                                                                        opening_cash:
                                                                                            '',
                                                                                    },
                                                                                );
                                                                                setOpening(
                                                                                    {
                                                                                        outlet,
                                                                                        register,
                                                                                    },
                                                                                );
                                                                            }}
                                                                        >
                                                                            Open
                                                                            shift
                                                                        </Button>
                                                                    ) : null}
                                                                </div>
                                                            </article>
                                                        );
                                                    },
                                                )}
                                            </div>
                                        )}
                                    </section>
                                );
                            })}
                        </div>
                    )}
                </div>
            </main>

            <FormOverlay
                variant="dialog"
                open={opening !== null}
                onOpenChange={(value) => {
                    if (!value) {
                        setOpening(null);
                        openForm.clearErrors();
                    }
                }}
                title={`Open shift on ${opening?.register.name ?? ''}`}
                description="Count the cash in the drawer. It starts the shift's cash total."
                errors={openForm.errors}
                processing={openForm.processing}
                submitLabel="Open shift"
                onSubmit={() =>
                    openForm.post(open.url(), {
                        onSuccess: () => setOpening(null),
                    })
                }
            >
                <Field data-invalid={!!openForm.errors.opening_cash}>
                    <FieldLabel htmlFor="opening-cash">
                        {currencyCode
                            ? `Opening cash (${currencyCode})`
                            : 'Opening cash'}
                    </FieldLabel>
                    <Input
                        id="opening-cash"
                        name="opening_cash"
                        inputMode="decimal"
                        placeholder="0.00"
                        className="min-h-11 tabular-nums"
                        aria-invalid={!!openForm.errors.opening_cash}
                        value={openForm.data.opening_cash}
                        onChange={(event) =>
                            openForm.setData('opening_cash', event.target.value)
                        }
                        autoFocus
                    />
                    <FieldError>{openForm.errors.opening_cash}</FieldError>
                </Field>
            </FormOverlay>

            {leaveTerminalDialog}

            <Dialog open={switching} onOpenChange={setSwitching}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Switch user</DialogTitle>
                        <DialogDescription>
                            Tap your name and enter your PIN to switch
                            operators.
                        </DialogDescription>
                    </DialogHeader>
                    <PosPinPad
                        key={switchKey}
                        staff={staff}
                        onDone={() => setSwitching(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
