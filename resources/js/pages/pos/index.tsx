import { Head, Link, router, useForm } from '@inertiajs/react';
import { Lock, LogOut, Store, UserRound } from 'lucide-react';
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
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { enter, exit } from '@/routes/pos/terminal-mode';
import { terminal } from '@/routes/pos';
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
    canShift: boolean;
    staff: PosStaff[];
    outlets: Outlet[];
};

export default function PosIndex({
    locked,
    hasDevice,
    actor,
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

    if (locked && actor === null) {
        return (
            <>
                <Head title="POS" />
                <div className="mx-auto flex w-full max-w-lg flex-1 flex-col gap-6 p-4">
                    <PageHeader
                        title="Who is selling?"
                        description="Tap your name, then key your PIN."
                    />
                    {!hasDevice && (
                        <Alert>
                            <AlertTitle>
                                This browser is not a registered terminal
                            </AlertTitle>
                            <AlertDescription>
                                A manager registers it in Settings, POS, Staff
                                PINs. Until then PIN sign-in is refused.
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
                        <PosPinPad staff={staff} />
                    )}
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="POS" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Point of sale"
                    description={
                        actor ? `Signed in as ${actor.name}.` : undefined
                    }
                    actions={
                        locked ? (
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setSwitching(true)}
                                >
                                    <UserRound data-icon="inline-start" />
                                    Switch user
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setLeaving(true)}
                                >
                                    <LogOut data-icon="inline-start" />
                                    Leave Terminal Mode
                                </Button>
                            </div>
                        ) : (
                            <Button onClick={() => router.post(enter.url())}>
                                <Lock data-icon="inline-start" />
                                Enter Terminal Mode
                            </Button>
                        )
                    }
                />

                {outlets.length === 0 ? (
                    <EmptyState
                        icon={Store}
                        title="No outlet is ready to sell"
                        description="A manager enables POS on an outlet and adds a register in Settings, POS."
                    />
                ) : (
                    outlets.map((outlet) => (
                        <Card key={outlet.id}>
                            <CardHeader>
                                <CardTitle>{outlet.name}</CardTitle>
                                <CardDescription>
                                    Pick a register to sell from.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {outlet.registers.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        This outlet has no register yet.
                                    </p>
                                )}
                                {outlet.registers.map((register) => (
                                    <div
                                        key={register.id}
                                        className="flex flex-col gap-3 rounded-lg border p-4"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="font-medium">
                                                {register.name}
                                            </p>
                                            {register.shiftNumber && (
                                                <Badge variant="secondary">
                                                    {register.shiftStatus}
                                                </Badge>
                                            )}
                                        </div>
                                        {register.shiftNumber ? (
                                            <Link
                                                className={buttonVariants()}
                                                href={terminal.url(
                                                    { outlet: outlet.id },
                                                    {
                                                        query: {
                                                            register:
                                                                register.id,
                                                        },
                                                    },
                                                )}
                                            >
                                                Open register (
                                                {register.shiftNumber})
                                            </Link>
                                        ) : null}
                                        {register.shiftNumber ? (
                                            <Link
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                })}
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
                                        ) : (
                                            <Button
                                                variant="outline"
                                                disabled={!canShift}
                                                onClick={() => {
                                                    openForm.setData({
                                                        register_id: String(
                                                            register.id,
                                                        ),
                                                        opening_cash: '',
                                                    });
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
                                ))}
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>

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

            <FormOverlay
                variant="dialog"
                open={switching}
                onOpenChange={setSwitching}
                title="Switch user"
                errors={{}}
                processing={false}
                submitLabel="Close"
                onSubmit={() => setSwitching(false)}
            >
                <PosPinPad staff={staff} onDone={() => setSwitching(false)} />
            </FormOverlay>
        </>
    );
}
