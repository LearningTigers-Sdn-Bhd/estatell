import { Head, Link, router, useForm } from '@inertiajs/react';
import { Clock, Lock, LogOut, Store, UserRound } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import { PosPinPad } from '@/components/pms/pos/pos-pin-pad';
import type { PosStaff } from '@/components/pms/pos/pos-types';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { enter, exit } from '@/routes/pos/terminal-mode';
import { clock as posClock, terminal } from '@/routes/pos';
import { show as offlineShow } from '@/routes/pos/offline';
import { open } from '@/routes/pos/shifts';

type Register = {
    id: number;
    name: string;
    shiftNumber: string | null;
    shiftStatus: string | null;
};

type Outlet = { id: number; name: string; registers: Register[] };

type Props = {
    locked: boolean;
    hasDevice: boolean;
    actor: PosStaff | null;
    clock: { clockedInOutletId: number | null } | null;
    canShift: boolean;
    staff: PosStaff[];
    outlets: Outlet[];
};

export default function PosIndex({
    locked,
    hasDevice,
    actor,
    clock,
    canShift,
    staff,
    outlets,
}: Props) {
    const [opening, setOpening] = useState<{
        outlet: Outlet;
        register: Register;
    } | null>(null);
    const [leaving, setLeaving] = useState(false);
    const [switching, setSwitching] = useState(false);
    const openForm = useForm({ register_id: '', opening_cash: '' });
    const exitForm = useForm({ password: '' });

    const clockIn = (outletId: number) =>
        router.post(
            posClock.url(),
            { action: 'in', outlet_id: outletId },
            { preserveScroll: true },
        );

    const clockOut = () =>
        router.post(
            posClock.url(),
            { action: 'out' },
            { preserveScroll: true },
        );

    if (locked && actor === null) {
        return (
            <>
                <Head title="POS" />
                <main className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center gap-6 p-6">
                    <PageHeader
                        title="Who is selling?"
                        description="Tap your name, then key your PIN. Not listed? Set your POS PIN under Account first."
                    />
                    {!hasDevice && (
                        <Alert>
                            <AlertTitle>
                                This browser is not a registered terminal
                            </AlertTitle>
                            <AlertDescription>
                                A manager registers it in POS, Staff &amp;
                                terminals. Until then PIN sign-in is refused.
                            </AlertDescription>
                        </Alert>
                    )}
                    {staff.length === 0 ? (
                        <EmptyState
                            icon={UserRound}
                            title="No staff have a PIN yet"
                            description="Staff set their own PIN under Account, POS PIN."
                        />
                    ) : (
                        <Card>
                            <CardContent className="pt-6">
                                <PosPinPad staff={staff} />
                            </CardContent>
                        </Card>
                    )}
                </main>
            </>
        );
    }

    return (
        <>
            <Head title="POS" />
            <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Point of sale"
                    description={
                        actor ? `Signed in as ${actor.name}.` : undefined
                    }
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {clock?.clockedInOutletId !== null &&
                                clock?.clockedInOutletId !== undefined && (
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={clockOut}
                                    >
                                        <Clock
                                            data-icon="inline-start"
                                            aria-hidden="true"
                                        />
                                        Clock out
                                    </Button>
                                )}
                            {locked ? (
                                <>
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() => setSwitching(true)}
                                    >
                                        <UserRound
                                            data-icon="inline-start"
                                            aria-hidden="true"
                                        />
                                        Switch user
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() => setLeaving(true)}
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
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => router.post(enter.url())}
                                >
                                    <Lock
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
                                    Enter Terminal Mode
                                </Button>
                            )}
                        </div>
                    }
                />

                {outlets.length === 0 ? (
                    <EmptyState
                        icon={Store}
                        title="No outlet is ready to sell"
                        description="A manager enables POS on an outlet and adds a register in POS, Outlets."
                    />
                ) : (
                    outlets.map((outlet) => {
                        const clockedHere =
                            clock?.clockedInOutletId === outlet.id;

                        return (
                            <Card key={outlet.id}>
                                <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                                    <div className="flex flex-col gap-1">
                                        <CardTitle className="font-heading text-xl">
                                            {outlet.name}
                                        </CardTitle>
                                        <CardDescription>
                                            Pick a register to open or resume a
                                            shift.
                                        </CardDescription>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {clockedHere ? (
                                            <Badge variant="secondary">
                                                Clocked in
                                            </Badge>
                                        ) : (
                                            clock?.clockedInOutletId ===
                                                null && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="min-h-11"
                                                    onClick={() =>
                                                        clockIn(outlet.id)
                                                    }
                                                >
                                                    <Clock
                                                        data-icon="inline-start"
                                                        aria-hidden="true"
                                                    />
                                                    Clock in
                                                </Button>
                                            )
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {outlet.registers.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            This outlet has no register yet.
                                        </p>
                                    )}
                                    {outlet.registers.map((register) => {
                                        const isOpen =
                                            register.shiftNumber !== null;

                                        return (
                                            <div
                                                key={register.id}
                                                className={
                                                    isOpen
                                                        ? 'flex flex-col justify-between gap-4 rounded-xl border border-primary/40 bg-primary/5 p-4'
                                                        : 'flex flex-col justify-between gap-4 rounded-xl border bg-background p-4'
                                                }
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="flex flex-col gap-0.5">
                                                        <p className="font-heading font-semibold text-foreground">
                                                            {register.name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground tabular-nums">
                                                            {isOpen
                                                                ? `Shift ${register.shiftNumber}`
                                                                : 'No active shift'}
                                                        </p>
                                                    </div>
                                                    {isOpen ? (
                                                        <Badge variant="secondary">
                                                            {
                                                                register.shiftStatus
                                                            }
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline">
                                                            Closed
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="flex flex-col gap-2">
                                                    {isOpen ? (
                                                        <>
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
                                                                Open register
                                                            </Link>
                                                            <Link
                                                                className={`${buttonVariants(
                                                                    {
                                                                        variant:
                                                                            'outline',
                                                                    },
                                                                )} min-h-11`}
                                                                href={offlineShow.url(
                                                                    undefined,
                                                                    {
                                                                        query: {
                                                                            outlet: outlet.id,
                                                                        },
                                                                    },
                                                                )}
                                                            >
                                                                Offline walk-in
                                                            </Link>
                                                        </>
                                                    ) : (
                                                        <Button
                                                            variant="outline"
                                                            className="min-h-11"
                                                            disabled={!canShift}
                                                            onClick={() => {
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
                                                                setOpening({
                                                                    outlet,
                                                                    register,
                                                                });
                                                            }}
                                                        >
                                                            Open shift
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </CardContent>
                            </Card>
                        );
                    })
                )}
            </main>

            <FormOverlay
                variant="dialog"
                open={opening !== null}
                onOpenChange={(value) => !value && setOpening(null)}
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
                    <FieldLabel htmlFor="opening-cash">Opening cash</FieldLabel>
                    <Input
                        id="opening-cash"
                        inputMode="decimal"
                        placeholder="0.00"
                        className="tabular-nums"
                        value={openForm.data.opening_cash}
                        onChange={(event) =>
                            openForm.setData('opening_cash', event.target.value)
                        }
                        autoFocus
                    />
                </Field>
            </FormOverlay>

            <FormOverlay
                variant="dialog"
                open={leaving}
                onOpenChange={setLeaving}
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
                <Field>
                    <FieldLabel htmlFor="exit-password">Password</FieldLabel>
                    <Input
                        id="exit-password"
                        type="password"
                        autoComplete="current-password"
                        value={exitForm.data.password}
                        onChange={(event) =>
                            exitForm.setData('password', event.target.value)
                        }
                        autoFocus
                    />
                </Field>
            </FormOverlay>

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
                        staff={staff}
                        onDone={() => setSwitching(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
